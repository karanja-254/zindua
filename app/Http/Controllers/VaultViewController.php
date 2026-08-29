<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class VaultViewController extends Controller
{
    /**
     * Render the ProofVault SPA (cover + control room).
     */
    public function __invoke(): View
    {
        return view('vault');
    }
}
