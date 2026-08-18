<?php

namespace App\Modules\Settings\Controllers;

use Illuminate\Http\JsonResponse;

class CountryListController
{
    public function index(): JsonResponse
    {
        $countries = [
            ['code' => 'NG', 'name' => 'Nigeria'],
            ['code' => 'GH', 'name' => 'Ghana'],
            ['code' => 'KE', 'name' => 'Kenya'],
            ['code' => 'UG', 'name' => 'Uganda'],
            ['code' => 'TZ', 'name' => 'Tanzania'],
            ['code' => 'ET', 'name' => 'Ethiopia'],
            ['code' => 'SN', 'name' => 'Senegal'],
            ['code' => 'CI', 'name' => 'Ivory Coast'],
            ['code' => 'CM', 'name' => 'Cameroon'],
            ['code' => 'ZA', 'name' => 'South Africa'],
            ['code' => 'EG', 'name' => 'Egypt'],
            ['code' => 'MA', 'name' => 'Morocco'],
            ['code' => 'RW', 'name' => 'Rwanda'],
            ['code' => 'BJ', 'name' => 'Benin'],
            ['code' => 'BW', 'name' => 'Botswana'],
            ['code' => 'BI', 'name' => 'Burundi'],
            ['code' => 'CD', 'name' => 'Democratic Republic of the Congo'],
            ['code' => 'CG', 'name' => 'Republic of the Congo'],
            ['code' => 'DJ', 'name' => 'Djibouti'],
            ['code' => 'ER', 'name' => 'Eritrea'],
            ['code' => 'GA', 'name' => 'Gabon'],
            ['code' => 'GM', 'name' => 'Gambia'],
            ['code' => 'GN', 'name' => 'Guinea'],
            ['code' => 'GW', 'name' => 'Guinea-Bissau'],
            ['code' => 'LR', 'name' => 'Liberia'],
            ['code' => 'LY', 'name' => 'Libya'],
            ['code' => 'MW', 'name' => 'Malawi'],
            ['code' => 'ML', 'name' => 'Mali'],
            ['code' => 'MZ', 'name' => 'Mozambique'],
            ['code' => 'NA', 'name' => 'Namibia'],
            ['code' => 'NE', 'name' => 'Niger'],
            ['code' => 'SC', 'name' => 'Seychelles'],
            ['code' => 'SL', 'name' => 'Sierra Leone'],
            ['code' => 'SO', 'name' => 'Somalia'],
            ['code' => 'SS', 'name' => 'South Sudan'],
            ['code' => 'SD', 'name' => 'Sudan'],
            ['code' => 'SZ', 'name' => 'Eswatini'],
            ['code' => 'TD', 'name' => 'Chad'],
            ['code' => 'TG', 'name' => 'Togo'],
            ['code' => 'TN', 'name' => 'Tunisia'],
            ['code' => 'ZM', 'name' => 'Zambia'],
            ['code' => 'ZW', 'name' => 'Zimbabwe'],
        ];

        return response()->json(['countries' => $countries]);
    }
}
