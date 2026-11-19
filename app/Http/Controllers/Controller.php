<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

abstract class Controller
{
    public function getPerPage($page = 10)
    {
        return $page;
    }


    public static function getMasterCode(string $table_name, string $prefix_name, int $tenantId, $request = null)
    {
        $code = '';

        $insideLoop = true;

        $plusOnSeq = 0;

        $checkCustomFormat = DB::table('custom_code_generators')->where('tenant_id', $tenantId)
            ->where('table_name', $table_name)
            ->first();

        if ($checkCustomFormat) {
            $request->merge(['numbering_format' => $checkCustomFormat->numbering_format]);

            $arrayOfCodeFormat = explode(' ', $checkCustomFormat->code_format);

            if (sizeof($arrayOfCodeFormat) > 0) {
                while ($insideLoop) {
                    for ($i = 0; $i < sizeof($arrayOfCodeFormat); $i++) {
                        $code .= (substr($arrayOfCodeFormat[$i], 0, 1) == '#') ? self::generateVariableFormatCode(ltrim($arrayOfCodeFormat[$i], $arrayOfCodeFormat[$i][0]), $table_name, $tenantId, $prefix_name, $plusOnSeq, $request) : $arrayOfCodeFormat[$i];
                    }

                    $checkExisting = DB::table($table_name)
                        ->where('tenant_id', $tenantId)
                        ->where('code', $code);

                    if ($request) {
                        if ($request->location_id) {
                            $checkExisting = $checkExisting->where('location_id', $request->location_id);
                        }
                    }

                    $checkExisting = $checkExisting->first();

                    if ($checkExisting) {
                        $plusOnSeq++;
                        $code = '';
                    } else {
                        $insideLoop = false;
                    }
                }
            } else {
                $code = self::generateDefaultFormatCode($table_name, $prefix_name, $tenantId);
            }
        } else {
            $code = self::generateDefaultFormatCode($table_name, $prefix_name, $tenantId);
        }

        return $code;
    }

    private static function generateDefaultFormatCode(string $table_name, string $prefix_name, int $tenantId)
    {
        $total_data = DB::table($table_name)->where('tenant_id', $tenantId);

        $total_data = $total_data->count();

        $sequence = $total_data + 1;

        $check_unique_code = false;

        while (!$check_unique_code) {
            $code = $prefix_name . '-' . $sequence;

            $get_detail_data = DB::table($table_name)
                ->where('code', $code)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($get_detail_data == null) {
                $check_unique_code = true;
            } else {
                $sequence++;
                $check_unique_code = false;
            }
        }

        return $code;
    }

    private static function generateVariableFormatCode(string $var, string $table_name, int $tenantId, string $prefix_name, int $plusOnSeq = 0, $request = null)
    {
        switch ($var) {
            case 'date':
                return date('d');
                break;
            case 'employee_code':
                $code = '';

                if ($request) {
                    $code = $request->employee_code;
                }

                return $code;

                break;
            case 'is_renewal':
                $code = '';

                if ($request) {
                    $code = ($request->is_renewal) ? 'RN-' : 'N-';
                }

                return $code;

                break;
            case 'location_code':
                $code = '';

                if ($request) {
                    $code = $request->location_code;
                }

                return $code;

                break;
            case 'month':
                return date('m');
                break;
            case 'month_in_romawi':
                switch (intval(date('m'))) {
                    case 1:
                        return 'I';
                        break;
                    case 2:
                        return 'II';
                        break;
                    case 3:
                        return 'III';
                        break;
                    case 4:
                        return 'IV';
                        break;
                    case 5:
                        return 'V';
                        break;
                    case 6:
                        return 'VI';
                        break;
                    case 7:
                        return 'VII';
                        break;
                    case 8:
                        return 'VIII';
                        break;
                    case 9:
                        return 'IX';
                        break;
                    case 10:
                        return 'X';
                        break;
                    case 11:
                        return 'XI';
                        break;
                    case 12:
                        return 'XII';
                        break;
                }

                break;
            case 'prefix':
                return ($prefix_name != null) ? $prefix_name : '';
                break;
            case 'product_category_code':
                $code = '';

                if ($request) {
                    $code = $request->product_category_code;
                }

                return $code;

                break;
            case 'product_code':
                $code = '';

                if ($request) {
                    $code = $request->product_code;
                }

                return $code;

                break;
            case 'seq_rep_in_year_with_4_digits':
                $lastNumber = self::countLastNumberOfTable($table_name, $tenantId, $request);

                $seq = 1 + $lastNumber + $plusOnSeq;

                return self::getDigitsInNumber($seq);

                break;
            case 'seq_rep_in_year_with_5_digits':
                $lastNumber = self::countLastNumberOfTable($table_name, $tenantId, $request);

                $seq = 1 + $lastNumber + $plusOnSeq;

                return self::getDigitsInNumber($seq, 5);

                break;
            case 'seq_rep_in_year_with_6_digits':
                $lastNumber = self::countLastNumberOfTable($table_name, $tenantId, $request);

                $seq = 1 + $lastNumber + $plusOnSeq;

                return self::getDigitsInNumber($seq, 6);

                break;
            case 'seq_rep_in_year':
                $lastNumber = self::countLastNumberOfTable($table_name, $tenantId, $request);

                $seq = 1 + $lastNumber + $plusOnSeq;

                return $seq;

                break;
            case 'tenant_code':
                return "TENANT-CODE";
                break;
            case 'year':
                return date('Y');
                break;
            case 'year_2_digits':
                return date('y');
                break;
            default:
                return '';
                break;
        }
    }

    public static function getDigitsInNumber($number, $totalNumber = 4)
    {
        $numberFormat = "";

        $startNumber = 1;

        if ($number < 10) {
            $startNumber = 1;
        } else if ($number >= 10 && $number < 100) {
            $startNumber = 2;
        } else if ($number >= 100 && $number < 1000) {
            $startNumber = 3;
        } else if ($number >= 1000 && $number < 10000) {
            $startNumber = 4;
        } else if ($number >= 10000 && $number < 100000) {
            $startNumber = 5;
        }

        for ($i = $startNumber; $i < $totalNumber; $i++) {
            $numberFormat .= "0";
        }

        $numberFormat .= $number;

        return $numberFormat;
    }
}
