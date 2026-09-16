<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Updates the authenticated user's own profile fields. This is what
     * every "Pay" button elsewhere in the app relies on -- a settlement's
     * UPI deep link is always built from the recipient's *current*
     * upi_id at the moment someone taps Pay, so saving it here is what
     * actually makes that link work.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            // A UPI VPA is "handle@bank" -- no spaces, exactly one "@".
            'upi_id' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[\w.+-]{2,256}@[a-zA-Z][\w.-]{1,63}$/'],
        ]);

        $request->user()->update($data);

        return response()->json(['user' => $request->user()->fresh()]);
    }
}
