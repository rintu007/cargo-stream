<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ZieglerPdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "PALLETS" => "pallet",
        "PALLET" => "pallet",
        "CARTONS" => "carton",
        "BOXES" => "carton",
    ];

    public static function validateFormat(array $lines) {
        $cleanLines = array_map('trim', $lines);
        $cleanLines = array_filter($cleanLines);
        $cleanLines = array_values($cleanLines);
        
        return count($cleanLines) > 5 
            && Str::contains($cleanLines[0] ?? '', 'ZIEGLER UK LTD')
            && Str::contains($cleanLines[1] ?? '', 'LONDON GATEWAY LOGISTICS PARK');
    }

    public function processLines(array $lines, ?string $attachment_filename = null) {
        $cleanLines = array_map('trim', $lines);
        $cleanLines = array_filter($cleanLines);
        $cleanLines = array_values($cleanLines);
        
        if (!static::validateFormat($cleanLines)) {
            throw new \Exception("Invalid Ziegler PDF");
        }

        // Extract order reference
        $refLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'Ziegler Ref'));
        $order_reference = '';
        if ($refLine !== false && isset($cleanLines[$refLine + 1])) {
            $order_reference = trim($cleanLines[$refLine + 1]);
        }

        // Extract freight price
        $rateLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'Rate'));
        $freight_price = 0;
        $freight_currency = 'EUR';
        if ($rateLine !== false && isset($cleanLines[$rateLine + 1])) {
            $priceText = $cleanLines[$rateLine + 1];
            if (preg_match('/€\s*([0-9,.]+)/', $priceText, $matches)) {
                $freight_price = (float) str_replace(',', '', $matches[1]);
            } elseif (preg_match('/([0-9,.]+)/', $priceText, $matches)) {
                $freight_price = (float) str_replace(',', '', $matches[1]);
            }
        }

        // Extract customer information
        $customer = [
            'side' => 'none',
            'details' => $this->extractZieglerInfo($cleanLines)
        ];

        // Extract locations and cargos
        $loading_locations = $this->extractLoadingLocations($cleanLines);
        $destination_locations = $this->extractDestinationLocations($cleanLines);
        $cargos = $this->extractCargos($cleanLines);

        $attachment_filenames = [$attachment_filename ?? ''];

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'freight_price',
            'freight_currency'
        );

        return $this->createOrder($data);
    }

    protected function extractZieglerInfo(array $cleanLines) {
        return [
            'company' => 'ZIEGLER UK LTD',
            'street_address' => 'LONDON GATEWAY LOGISTICS PARK, NORTH 4, NORTH SEA CROSSING',
            'city' => 'STANFORD LE HOPE',
            'postal_code' => 'SS17 9FJ',
            'country' => 'GB',
            'contact_person' => '',
            'email' => '',
            'vat_code' => ''
        ];
    }

    protected function extractLoadingLocations(array $cleanLines) {
        $locations = [];
        
        // Find all collection points using a more flexible search
        for ($i = 0; $i < count($cleanLines); $i++) {
            if (trim($cleanLines[$i]) === 'Collection') {
                $location = $this->extractLocationSection($cleanLines, $i, 'loading');
                if ($location) {
                    $locations[] = $location;
                }
            }
        }
        
        return $locations;
    }

    protected function extractDestinationLocations(array $cleanLines) {
        $locations = [];
        
        // Find delivery points
        for ($i = 0; $i < count($cleanLines); $i++) {
            if (trim($cleanLines[$i]) === 'Delivery') {
                $location = $this->extractLocationSection($cleanLines, $i, 'delivery');
                if ($location) {
                    $locations[] = $location;
                }
            }
        }
        
        // Also check clearance points as potential destinations
        for ($i = 0; $i < count($cleanLines); $i++) {
            if (trim($cleanLines[$i]) === 'Clearance') {
                $location = $this->extractLocationSection($cleanLines, $i, 'delivery');
                if ($location) {
                    $locations[] = $location;
                }
            }
        }
        
        return $locations;
    }

    protected function extractLocationSection(array $cleanLines, int $startIndex, string $type) {
    $sectionData = [
        'company' => '',
        'reference' => '',
        'address_lines' => [],
        'date' => '',
        'time' => '',
        'cargo_info' => ''
    ];
    
    $i = $startIndex + 1;
    $maxLines = min($startIndex + 15, count($cleanLines));
    $linesProcessed = 0;
    
    while ($i < $maxLines && $linesProcessed < 10) {
        $line = $cleanLines[$i];
        
        if (empty($line)) {
            $i++;
            continue;
        }
        
        // Stop if we hit the next section OR if we hit the footer (starts with "-")
        if (in_array($line, ['Collection', 'Delivery', 'Clearance']) || 
            str_starts_with($line, '- ') || 
            str_starts_with($line, 'Please find below')) {
            break;
        }
        
        // Skip REF label but capture the reference value
        if ($line === 'REF') {
            if (isset($cleanLines[$i + 1]) && $cleanLines[$i + 1] !== 'REF') {
                $sectionData['reference'] = $cleanLines[$i + 1];
                $i++; // Skip the next line since we used it
            }
            $i++;
            $linesProcessed++;
            continue;
        }
        
        // Date (DD/MM/YYYY format)
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) {
            $sectionData['date'] = $line;
        }
        // Time (HHMM-HHMM format)
        elseif (preg_match('/^\d{4}-\d{4}$/', $line)) {
            $sectionData['time'] = $line;
        }
        // Cargo information (e.g., "66 PALLETS")
        elseif (preg_match('/^\d+\s+(PALLETS?|CARTONS?|BOXES)/i', $line)) {
            $sectionData['cargo_info'] = $line;
        }
        // Company name (first non-empty line that's not a date/time/ref)
        elseif (empty($sectionData['company']) && !preg_match('/^\d/', $line)) {
            $sectionData['company'] = $line;
        }
        // Address lines - but skip obvious non-address lines
        elseif (!preg_match('/^(delivery slot|please|payment|all business|delivery to|please quote|this booking|please confirm|please ensure)/i', $line)) {
            $sectionData['address_lines'][] = $line;
        }
        
        $i++;
        $linesProcessed++;
    }
    
    // Parse the address information
    $addressInfo = $this->parseAddress($sectionData['address_lines'], $sectionData['company']);
    
    if (!empty($sectionData['reference'])) {
        $addressInfo['comment'] = $sectionData['reference'];
    }
    
    // Parse date/time
    $timeInfo = $this->parseDateTime($sectionData['date'], $sectionData['time']);
    
    return [
        'company_address' => $addressInfo,
        'time' => $timeInfo
    ];
}

    protected function parseAddress(array $addressLines, string $company) {
    // Filter out any lines that are clearly not part of the address
    $filteredLines = array_filter($addressLines, function($line) {
        $excludedPatterns = [
            '/^delivery slot/i',
            '/^please/i',
            '/^payment/i',
            '/^all business/i',
            '/^delivery to/i',
            '/^please quote/i',
            '/^this booking/i',
            '/^please confirm/i',
            '/^please ensure/i',
            '/^- /' // Lines starting with dash (bullet points)
        ];
        
        foreach ($excludedPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return false;
            }
        }
        return true;
    });
    
    $fullAddress = implode(', ', array_values($filteredLines));
    $result = [
        'company' => $company,
        'street_address' => $fullAddress,
        'city' => '',
        'postal_code' => '',
        'country' => ''
    ];

    Log::info("Parsing Ziegler address: " . $fullAddress);

    // Format 1: UK address with postal code at end (LEIGHTON BUZZARD, LU7 4UH)
    if (preg_match('/(.*),\s*([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})$/i', $fullAddress, $match)) {
        $result['street_address'] = trim($match[1]);
        $result['postal_code'] = preg_replace('/\s+/', '', $match[2]);
        $result['country'] = 'GB';
        
        // Extract city from the street address part
        $addressParts = explode(',', $result['street_address']);
        if (count($addressParts) > 1) {
            $result['city'] = trim(end($addressParts));
            $result['street_address'] = trim(implode(',', array_slice($addressParts, 0, -1)));
        }
    }
    // Format 2: French address with FR prefix (ENNERY, FR-57365)
    elseif (preg_match('/(.*),\s*FR-?(\d{5})$/i', $fullAddress, $match)) {
        $result['street_address'] = trim($match[1]);
        $result['postal_code'] = $match[2];
        $result['country'] = 'FR';
        
        $addressParts = explode(',', $result['street_address']);
        if (count($addressParts) > 1) {
            $result['city'] = trim(end($addressParts));
            $result['street_address'] = trim(implode(',', array_slice($addressParts, 0, -1)));
        }
    }
    // Format 3: French address without FR prefix but with postal code (STIRING WENDEL, FR57350)
    elseif (preg_match('/(.*),\s*([A-Z]{2}\d{5})$/i', $fullAddress, $match)) {
        $result['street_address'] = trim($match[1]);
        $result['postal_code'] = substr($match[2], 2);
        $result['country'] = 'FR';
        
        $addressParts = explode(',', $result['street_address']);
        if (count($addressParts) > 1) {
            $result['city'] = trim(end($addressParts));
            $result['street_address'] = trim(implode(',', array_slice($addressParts, 0, -1)));
        }
    }
    // Format 4: UK address with postal code in middle (TN25 6GE Ashford)
    elseif (preg_match('/(.*)\s+([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\s+(.+)$/i', $fullAddress, $match)) {
        $result['street_address'] = trim($match[1]);
        $result['postal_code'] = preg_replace('/\s+/', '', $match[2]);
        $result['city'] = trim($match[3]);
        $result['country'] = 'GB';
    }
    // Format 5: Simple French address with postal code at end (STIRING WENDEL, 57350)
    elseif (preg_match('/(.*),\s*(\d{5})$/i', $fullAddress, $match)) {
        $result['street_address'] = trim($match[1]);
        $result['postal_code'] = $match[2];
        $result['country'] = 'FR';
        
        $addressParts = explode(',', $result['street_address']);
        if (count($addressParts) > 1) {
            $result['city'] = trim(end($addressParts));
            $result['street_address'] = trim(implode(',', array_slice($addressParts, 0, -1)));
        }
    }

    // Clean up
    $result['street_address'] = trim($result['street_address'], " ,-");
    $result['city'] = trim($result['city'], " ,-");

    // If city is still empty, try to extract from address lines
    if (empty($result['city'])) {
        foreach ($filteredLines as $line) {
            // Look for city names (words that are all uppercase and more than 3 characters)
            $cleanLine = trim($line);
            if (strlen($cleanLine) > 2 && 
                !in_array($cleanLine, ['REF', 'PICK', 'UP', 'T1', 'RUE ROBERT SCHUMANN']) &&
                !preg_match('/^\d/', $cleanLine)) {
                $result['city'] = $cleanLine;
                break;
            }
        }
    }

    // Final country fallback
    if (empty($result['country'])) {
        if (strpos($fullAddress, 'UK') !== false || stripos($fullAddress, 'London') !== false) {
            $result['country'] = 'GB';
        } elseif (strpos($fullAddress, 'FR') !== false) {
            $result['country'] = 'FR';
        } else {
            $result['country'] = 'GB'; // Default to UK for Ziegler
        }
    }

    // Ensure city has minimum length
    if (strlen($result['city']) < 2) {
        $result['city'] = 'Unknown';
    }

    Log::info("Parsed address result: " . json_encode($result));

    return $result;
}

    protected function parseDateTime(string $date, string $time) {
        $result = [];
        
        if (!empty($date)) {
            try {
                $dateObj = Carbon::createFromFormat('d/m/Y', $date);
                
                if (!empty($time) && preg_match('/^(\d{4})-(\d{4})$/', $time, $matches)) {
                    $startTime = substr($matches[1], 0, 2) . ':' . substr($matches[1], 2, 2);
                    $endTime = substr($matches[2], 0, 2) . ':' . substr($matches[2], 2, 2);
                    
                    $result['datetime_from'] = $dateObj->copy()->setTimeFromTimeString($startTime)->toIsoString();
                    $result['datetime_to'] = $dateObj->copy()->setTimeFromTimeString($endTime)->toIsoString();
                } else {
                    $result['datetime_from'] = $dateObj->setTime(0, 0, 0)->toIsoString();
                }
            } catch (\Exception $e) {
                Log::error("Error parsing date: {$date} - " . $e->getMessage());
                $result['datetime_from'] = Carbon::now()->setTime(0, 0, 0)->toIsoString();
            }
        } else {
            $result['datetime_from'] = Carbon::now()->setTime(0, 0, 0)->toIsoString();
        }
        
        return $result;
    }

    protected function extractCargos(array $cleanLines) {
        $cargos = [];
        
        // Look for cargo information patterns throughout the document
        foreach ($cleanLines as $line) {
            // Look for pallet/carton counts (e.g., "66 PALLETS")
            if (preg_match('/^(\d+)\s+(PALLETS?|CARTONS?|BOXES)/i', $line, $matches)) {
                $package_count = (int)$matches[1];
                $package_type = $this->mapPackageType($matches[2]);
                
                $cargos[] = [
                    'title' => 'General Cargo',
                    'package_count' => $package_count,
                    'package_type' => $package_type,
                    'weight' => 0,
                    'ldm' => 0,
                    'number' => '',
                    'type' => $package_count > 10 ? 'FTL' : 'LTL'
                ];
                break;
            }
        }
        
        // If no specific cargo found, create a default one
        if (empty($cargos)) {
            $cargos[] = [
                'title' => 'General Cargo',
                'package_count' => 1,
                'package_type' => 'other',
                'weight' => 0,
                'ldm' => 0,
                'number' => '',
                'type' => 'FTL'
            ];
        }
        
        return $cargos;
    }

    protected function mapPackageType(string $type) {
        $normalized = strtoupper(trim($type));
        return static::PACKAGE_TYPE_MAP[$normalized] ?? "other";
    }

    // Helper function if array_find_key doesn't exist
    protected function array_find_key(array $array, callable $callback) {
        foreach ($array as $key => $value) {
            if ($callback($value)) {
                return $key;
            }
        }
        return false;
    }
}