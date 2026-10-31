
<?php
/**
 * brand.php
 *
 * Centralized company brand configuration.
 * Include after core.php.
 *
 * Requirements:
 * - PHP 7.4+
 * - PDO database connection in $pdo (when available)
 * - Existing companies table
 */

if (!function_exists('brand_defaults')) {
    function brand_defaults(): array
    {
        return [
            'company_name'     => 'Your Company Pvt. Ltd.',
            'company_short'    => 'Company',
            'company_tagline'  => 'Providing Quality Healthcare Solutions',
            'company_address'  => 'Your Address Line 1',
            'company_city'     => 'City',
            'company_state'    => 'State',
            'company_pincode'  => '000000',
            'company_gstin'    => 'GSTIN0000000000',
            'company_pan'      => 'PAN0000000',
            'company_phone'    => '+91-0000000000',
            'company_email'    => 'info@yourcompany.com',
            'company_website'  => 'www.yourcompany.com',
            'company_dl'       => 'DL-000000',
            'company_food_lic' => '',
            'system_name'      => 'Sales & Distribution ERP',
            'support_email'    => 'support@yourcompany.com',
            'logopath'         => '',
        ];
    }
}

if (!function_exists('load_brand')) {
    function load_brand(PDO $pdo): array
    {
        static $brand = null;

        // Cache the result for this PHP request.
        if ($brand !== null) {
            return $brand;
        }

        $brand = brand_defaults();

        try {
            // Assumes this application uses a single company.
            $stmt = $pdo->query(
                'SELECT * FROM companies LIMIT 1'
            );

            if ($stmt === false) {
                return $brand;
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!is_array($row)) {
                return $brand;
            }

            // Apply a database value only when it is a
            // non-empty string or another scalar value.
            $apply = static function (
                string $key,
                string $column
            ) use (&$brand, $row): void {
                if (
                    !array_key_exists($column, $row) ||
                    !is_scalar($row[$column])
                ) {
                    return;
                }

                $value = trim((string) $row[$column]);

                if ($value !== '') {
                    $brand[$key] = $value;
                }
            };

            // Company details.
            $apply('company_name',     'companyname');
            $apply('company_address',  'address');
            $apply('company_city',     'city');
            $apply('company_state',    'location');
            $apply('company_pincode',  'pincode');
            $apply('company_gstin',    'gstin');
            $apply('company_pan',      'panno');
            $apply('company_phone',    'phone');
            $apply('company_email',    'email');
            $apply('company_website',  'website');
            $apply('company_dl',       'dlno');
            $apply('company_food_lic', 'foodlicno');
            $apply('support_email',    'supportemail');

            // Preserve the original aboutus mapping.
            $apply('company_tagline', 'aboutus');

            // Support an explicit short-name column if present.
            if (
                isset($row['companyshort']) &&
                is_scalar($row['companyshort']) &&
                trim((string) $row['companyshort']) !== ''
            ) {
                $brand['company_short'] =
                    trim((string) $row['companyshort']);
            } elseif (
                isset($row['companyname']) &&
                is_scalar($row['companyname']) &&
                trim((string) $row['companyname']) !== ''
            ) {
                // Fall back to the first word of the name.
                $parts = preg_split(
                    '/\s+/u',
                    trim((string) $row['companyname']),
                    2
                );

                if (
                    is_array($parts) &&
                    isset($parts[0]) &&
                    $parts[0] !== ''
                ) {
                    $brand['company_short'] = $parts[0];
                }
            }

            // Accept a local path or HTTP(S) URL for the logo.
            if (
                isset($row['logopath']) &&
                is_string($row['logopath'])
            ) {
                $path = trim($row['logopath']);

                if (
                    $path !== '' &&
                    preg_match('/[\x00-\x20\x7F]/', $path) !== 1 &&
                    !str_starts_with($path, '//') &&
                    !str_contains($path, '\\')
                ) {
                    $isLocalPath = str_starts_with($path, '/')
                        || preg_match(
                            '/^(?![a-z][a-z0-9+.-]*:)[^?#]+$/i',
                            $path
                        ) === 1;

                    $isHttpUrl = preg_match(
                        '~^https?://~i',
                        $path
                    ) === 1;

                    if ($isLocalPath || $isHttpUrl) {
                        $brand['logopath'] = $path;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Keep defaults and log details on the server.
            error_log(
                'brand load failed: ' . get_class($e)
            );
        }

        return $brand;
    }
}

// Initialize brand even if the PDO connection is missing.
if (isset($pdo) && $pdo instanceof PDO) {
    $BRAND = load_brand($pdo);
} else {
    $BRAND = brand_defaults();
}
