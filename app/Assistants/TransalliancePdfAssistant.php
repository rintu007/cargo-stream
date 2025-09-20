<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TransalliancePdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "PACKAGING" => "other",
        "PAPER ROLLS" => "other",
    ];

    public static function validateFormat(array $lines) {
        $cleanLines = array_map('trim', $lines);
        $cleanLines = array_filter($cleanLines);
        $cleanLines = array_values($cleanLines);
        
        return count($cleanLines) > 5 
            && Str::contains($cleanLines[0] ?? '', 'Date/Time :')
            && array_find_key($cleanLines, fn($l) => Str::contains($l, 'TRANSALLIANCE TS LTD')) !== false
            && array_find_key($cleanLines, fn($l) => $l === 'CHARTERING CONFIRMATION') !== false;
    }

    public function processLines(array $lines, ?string $attachment_filename = null) {
        $cleanLines = array_map('trim', $lines);
        $cleanLines = array_filter($cleanLines);
        $cleanLines = array_values($cleanLines);
        
        if (!static::validateFormat($cleanLines)) {
            throw new \Exception("Invalid Transalliance PDF");
        }

        // Extract order reference
        $refLine = array_find_key($cleanLines, fn($l) => preg_match('/^REF\.:?/', $l));
        $order_reference = '';
        if ($refLine !== false && $refLine !== "" && isset($cleanLines[$refLine])) {
            // Extract the reference number using preg_match
            if (preg_match('/^REF\.:?\s*(\S+)/', $cleanLines[$refLine], $matches)) {
                $order_reference = trim($matches[1]);
            }
        }
        // Extract customer information
        $customer = [
            'side' => 'none',
            'details' => $this->extractTransallianceInfo($cleanLines)
        ];

        // Extract locations and cargos
        $loading_locations = [$this->extractLocation($cleanLines, 'Loading')];
        $destination_locations = [$this->extractLocation($cleanLines, 'Delivery')];
        $cargos = $this->extractCargos($cleanLines);

        // Extract freight price
        $freight_price = 0;
        $freight_currency = 'EUR';

        $priceLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'SHIPPING PRICE'));
        if ($priceLine !== false) {
            // Check the next line for the price value
            if (isset($cleanLines[$priceLine + 1])) {
                $priceValueLine = $cleanLines[$priceLine + 1];
                // Extract number with comma decimal separator
                if (preg_match('/^([0-9.,]+)/', $priceValueLine, $priceMatch)) {
                    $freight_price = uncomma($priceMatch[1]);
                }
            }
            
            // Optional: Check for currency on the line after price (if needed)
            if (isset($cleanLines[$priceLine + 2])) {
                $currencyLine = $cleanLines[$priceLine + 2];
                if (preg_match('/^([A-Z]{3})/', $currencyLine, $currencyMatch)) {
                    $freight_currency = $currencyMatch[1];
                }
            }
        }

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

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

        $this->createOrder($data);
    }

    protected function extractTransallianceInfo(array $cleanLines) {
        $transallianceLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'TRANSALLIANCE TS LTD'));
        
        $vatLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'VAT NUM:'));
        $vat_code = $vatLine && isset($cleanLines[$vatLine]) ? trim(str_replace('VAT NUM:', '', $cleanLines[$vatLine])) : '';
        
        $contactLine = array_find_key($cleanLines, fn($l) => Str::contains($l, 'Contact:') && !Str::contains($l, 'Tel :'));
        $contact_person = $contactLine && isset($cleanLines[$contactLine]) ? trim(str_replace('Contact:', '', $cleanLines[$contactLine])) : '';

        // Extract address lines
        $addressLines = [];
        if ($transallianceLine !== false) {
            for ($i = $transallianceLine + 1; $i < min($transallianceLine + 4, count($cleanLines)); $i++) {
                if (Str::contains($cleanLines[$i], 'Tel :') || Str::contains($cleanLines[$i], 'VAT NUM:')) break;
                $addressLines[] = $cleanLines[$i];
            }
        }
        $street_address = implode(', ', $addressLines);

        return [
            'company' => 'TRANSALLIANCE TS LTD',
            'street_address' => $street_address,
            'city' => 'BURTON UPON TRENT',
            'postal_code' => 'DE14 2WX',
            'country' => 'GB',
            'vat_code' => $vat_code,
            'contact_person' => $contact_person,
            'email' => 'invoice.ts@transalliance.eu'
        ];
    }

    protected function extractLocation(array $cleanLines, string $type) {
        $sectionLine = array_find_key($cleanLines, fn($l) => $l === $type);
        if ($sectionLine === false) {
            return [
                'company_address' => [
                    'company' => '', 
                    'street_address' => '', 
                    'city' => 'Unknown',
                    'postal_code' => '',
                    'country' => $type === 'Loading' ? 'GB' : 'FR'
                ],
                'time' => ['datetime_from' => Carbon::now()->setTime(0, 0, 0)->toIsoString()]
            ];
        }

        // Extract date and time
        $dateTime = [];
        $dateLine = $sectionLine + 1;
        if (isset($cleanLines[$dateLine])) {
            // Handle format: "ONE: 17/09/25 8h00 – 15h00"
            if (preg_match('/(\d{2}\/\d{2}\/\d{2})\s+(\d{1,2})h(\d{2})\s*–\s*(\d{1,2})h(\d{2})/', $cleanLines[$dateLine], $timeMatch)) {
                $dateTime = [
                    'datetime_from' => Carbon::createFromFormat('d/m/y H:i', $timeMatch[1] . ' ' . $timeMatch[2] . ':' . $timeMatch[3])->toIsoString(),
                    'datetime_to' => Carbon::createFromFormat('d/m/y H:i', $timeMatch[1] . ' ' . $timeMatch[4] . ':' . $timeMatch[5])->toIsoString()
                ];
            }
            // Handle format with just date
            elseif (preg_match('/(\d{2}\/\d{2}\/\d{2})/', $cleanLines[$dateLine], $dateMatch)) {
                $dateTime = [
                    'datetime_from' => Carbon::createFromFormat('d/m/y', $dateMatch[1])->setTime(0, 0, 0)->toIsoString()
                ];
            }
        }

        // Find the ON: line and then get company name after empty lines
        $company = '';
        $onLine = array_find_key($cleanLines, fn($l, $i) => $i > $sectionLine && $l === 'ON:');
        
        if ($onLine !== false) {
            // Look for the next non-empty line after ON: (skip empty lines)
            for ($i = $onLine + 1; $i < min($onLine + 5, count($cleanLines)); $i++) {
                if (!empty($cleanLines[$i]) && $cleanLines[$i] !== 'ON:' && !preg_match('/^\d{2}\/\d{2}\/\d{2}/', $cleanLines[$i])) {
                    $company = trim($cleanLines[$i]);
                    break;
                }
            }
        }

        // If we didn't find company after ON:, try to find it by looking for uppercase company names
        if (empty($company)) {
            for ($i = $sectionLine + 1; $i < min($sectionLine + 10, count($cleanLines)); $i++) {
                if (isset($cleanLines[$i]) && preg_match('/^[A-Z][A-Za-z\s]+$/', $cleanLines[$i]) && 
                    !Str::contains($cleanLines[$i], 'REFERENCE') && 
                    !Str::contains($cleanLines[$i], 'ON:') &&
                    !preg_match('/\d{2}\/\d{2}\/\d{2}/', $cleanLines[$i])) {
                    $company = trim($cleanLines[$i]);
                    break;
                }
            }
        }

        // Extract address lines - look for lines after the company name
        $addressLines = [];
        $companyLine = array_find_key($cleanLines, fn($l) => $l === $company);
        
        if ($companyLine !== false) {
            for ($i = $companyLine + 1; $i < min($companyLine + 5, count($cleanLines)); $i++) {
                if (!isset($cleanLines[$i]) || empty($cleanLines[$i]) || 
                    Str::contains($cleanLines[$i], 'Contact:') || 
                    Str::contains($cleanLines[$i], 'Instructions') ||
                    Str::contains($cleanLines[$i], 'LM . . . :') ||
                    Str::contains($cleanLines[$i], 'Weight . :')) {
                    break;
                }
                // Only add lines that look like address content
                if (preg_match('/[A-Za-z0-9]/', $cleanLines[$i])) {
                    $addressLines[] = $cleanLines[$i];
                }
            }
        }

        $fullAddress = implode(', ', $addressLines);
        $country = $type === 'Loading' ? 'GB' : 'FR';

        // Parse address components
        $addressInfo = $this->parseAddress($fullAddress, $country, $type);

        return [
            'company_address' => array_merge(['company' => $company], $addressInfo),
            'time' => !empty($dateTime) ? $dateTime : ['datetime_from' => Carbon::now()->setTime(0, 0, 0)->toIsoString()]
        ];
    }

    protected function parseAddress(string $address, string $country, string $type) {
        $result = [
            'street_address' => $address,
            'city' => $type === 'Loading' ? 'Peterborough' : 'Poce-sur-Cisse',
            'postal_code' => $type === 'Loading' ? 'PE2 6DP' : '37530',
            'country' => $country
        ];

        if ($country === 'GB') {
            // UK format: "BAKEWELL RD GB-PE2 6DP PETERBOROUGH"
            if (preg_match('/GB-([A-Z0-9\s]+)\s+([A-Za-z\s]+)$/', $address, $match)) {
                $result['postal_code'] = trim($match[1]);
                $result['city'] = trim($match[2]);
                $result['street_address'] = trim(str_replace("GB-{$match[1]} {$match[2]}", '', $address));
            }
            // Alternative UK format: "BAKEWELL RD, PETERBOROUGH, PE2 6DP"
            elseif (preg_match('/(.*?),\s*([A-Za-z\s]+),\s*([A-Z0-9\s]+)$/', $address, $match)) {
                $result['street_address'] = trim($match[1]);
                $result['city'] = trim($match[2]);
                $result['postal_code'] = trim($match[3]);
            }
        } else {
            // France format: "10 RTE DES INDUSTRIES -37530 POCE-SUR-CISSE"
            if (preg_match('/-(\d{5})\s+([A-Za-z\-]+)$/', $address, $match)) {
                $result['postal_code'] = $match[1];
                $result['city'] = $match[2];
                $result['street_address'] = trim(str_replace("-{$match[1]} {$match[2]}", '', $address));
            }
            // Alternative France format: "10 RTE DES INDUSTRIES, 37530 POCE-SUR-CISSE"
            elseif (preg_match('/(.*?),\s*(\d{5})\s+([A-Za-z\-]+)$/', $address, $match)) {
                $result['street_address'] = trim($match[1]);
                $result['postal_code'] = $match[2];
                $result['city'] = $match[3];
            }
        }

        // Ensure city has at least 2 characters
        if (strlen($result['city']) < 2) {
            $result['city'] = $type === 'Loading' ? 'Peterborough' : 'Poce-sur-Cisse';
        }

        return $result;
    }

    protected function extractCargos(array $cleanLines) {
    $weight = 0;
    $ldm = 0;
    $title = "General cargo";
    $otNumber = '';
    Log::info(json_encode($cleanLines));

    // Find ALL cargo sections first
    $cargoSections = [];
    for ($i = 0; $i < count($cleanLines) - 15; $i++) {
        if (isset($cleanLines[$i]) && Str::contains($cleanLines[$i], 'LM . . . :') &&
            isset($cleanLines[$i + 1]) && Str::contains($cleanLines[$i + 1], 'Parc. nb :') &&
            isset($cleanLines[$i + 2]) && Str::contains($cleanLines[$i + 2], 'Pal. nb. :') &&
            isset($cleanLines[$i + 3]) && Str::contains($cleanLines[$i + 3], 'Weight . :')) {
            $cargoSections[] = $i;
        }
    }

    Log::info(json_encode($cargoSections));

    // Use the LAST cargo section (under "Delivery")
    if (!empty($cargoSections)) {
        $i = end($cargoSections); // Get the last section index
        
        // Extract values with CORRECTED offsets
        if (isset($cleanLines[$i + 7]) && preg_match('/^\d{6}$/', trim($cleanLines[$i + 7]))) {
            $otNumber = trim($cleanLines[$i + 7]); // OT number at position +9
        }
        
        if (isset($cleanLines[$i + 8]) && preg_match('/^[\d,]+$/', trim($cleanLines[$i + 8]))) {
            $ldm = uncomma(trim($cleanLines[$i + 8])); // LDM at position +11
        }
        
        if (isset($cleanLines[$i + 9]) && preg_match('/^[\d,]+$/', trim($cleanLines[$i + 9]))) {
            $weight = uncomma(trim($cleanLines[$i + 9])); // Weight at position +13
        }
        
        if (isset($cleanLines[$i + 10]) && !empty(trim($cleanLines[$i + 10]))) {
            $title = trim($cleanLines[$i + 10]); // Title at position +15
        }
    }

    return [[
        'title' => $title,
        'package_count' => 1,
        'package_type' => $this->mapPackageType($title),
        'weight' => $weight,
        'ldm' => $ldm,
        'number' => $otNumber,
        'type' => $weight > 10000 ? 'FTL' : 'LTL'
    ]];
}

    protected function mapPackageType(string $type) {
        return static::PACKAGE_TYPE_MAP[$type] ?? "other";
    }
}