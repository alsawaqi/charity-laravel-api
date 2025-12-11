<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Region;
use App\Models\Country;
use App\Models\District;
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


    public function districts(Request $request)
    {
      $query = District::query()->orderBy('name');

      if ($request->filled('country_id')) {
          $query->where('country_id', $request->integer('country_id'));
      }
      if ($request->filled('region_id')) {
          $query->where('region_id', $request->integer('region_id'));
      }

      return response()->json(
          $query->get(['id', 'name'])
      );
    }

     public function cities(Request $request)
    {
      $query = City::query()->orderBy('name');

      if ($request->filled('country_id')) {
          $query->where('country_id', $request->integer('country_id'));
      }
      if ($request->filled('region_id')) {
          $query->where('region_id', $request->integer('region_id'));
      }
      if ($request->filled('district_id')) {
          $query->where('district_id', $request->integer('district_id'));
      }

      return response()->json(
          $query->get(['id', 'name'])
      );
    }
}
