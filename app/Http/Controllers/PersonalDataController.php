<?php

namespace App\Http\Controllers;

use App\Actions\Vault\ExportAdminPersonalData;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonalDataController extends Controller
{
    /**
     * Download the personal data of an admin as JSON. An admin may download their own, an Owner anyone's.
     */
    public function __invoke(Request $request, User $user, ExportAdminPersonalData $export): Response
    {
        $data = $export->handle($user, $request->user());

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="personal-data-'.$user->getKey().'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
