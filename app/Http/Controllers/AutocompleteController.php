<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AutocompleteController extends Controller
{
    /**
     * Search for suggestions
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 1) {
            return response()->json(['suggestions' => []]);
        }
        
        // get data from database
        $suggestions = DB::table('ins_rubber_models')
            ->where('name', 'like', '%' . $query . '%')
            ->limit(10)
            ->pluck('name');
        
        return response()->json(['suggestions' => $suggestions]);
    }
    
    /**
     * Search rubber colors
     */
    public function searchRubberColors(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 1) {
            return response()->json(['suggestions' => []]);
        }
        
        $suggestions = DB::table('ins_rubber_colors')
            ->where('name', 'like', '%' . $query . '%')
            ->limit(10)
            ->pluck('name');
        
        return response()->json(['suggestions' => $suggestions]);
    }
    
    /**
     * Search rubber models
     */
    public function searchRubberModels(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 1) {
            return response()->json(['suggestions' => []]);
        }
        
        $suggestions = DB::table('ins_rubber_models')
            ->where('name', 'like', '%' . $query . '%')
            ->limit(10)
            ->pluck('name');
        
        return response()->json(['suggestions' => $suggestions]);
    }
}
