<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Region;
use App\Models\City;
use Illuminate\Http\Request;

class LocationLookupController extends Controller
{
    public function countries()
    {
        return response()->json(
            Country::select('id', 'name')
                ->orderBy('name')
                ->get()
        );
    }

    public function regions(Request $request)
    {
        $countryId = $request->query('country_id');

        $query = Region::select('id', 'name', 'country_id')->orderBy('name');

        if ($countryId) {
            $query->where('country_id', $countryId);
        }

        return response()->json($query->get());
    }

    public function cities(Request $request)
    {
        $regionId = $request->query('region_id');

        $query = City::select('id', 'name', 'region_id')->orderBy('name');

        if ($regionId) {
            $query->where('region_id', $regionId);
        }

        return response()->json($query->get());
    }
}
