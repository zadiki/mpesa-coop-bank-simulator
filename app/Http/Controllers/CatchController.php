<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->boolean('reject')) {
            return response()->json(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Rejected']);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
