<?php

namespace App\Actions\Vault;

use App\Models\Credential;

class DeleteCredential
{
    /**
     * Delete a credential. Its project links are removed by the foreign key cascade.
     */
    public function handle(Credential $credential): void
    {
        $credential->delete();
    }
}
