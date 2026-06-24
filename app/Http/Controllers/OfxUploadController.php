<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ofx\StoreOfxRequest;
use App\Services\OfxParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class OfxUploadController extends Controller
{
    public function __construct(
        private OfxParserService $parser
    ) {}

    public function show()
    {
        return view('ofx.upload');
    }

    public function store(StoreOfxRequest $request): RedirectResponse
    {
        $arquivo = $request->file('ofx_file');
        $caminho = $arquivo->store('temp/ofx', 'local');
        $caminhoFull = Storage::disk('local')->path($caminho);

        $idtAssociacao = $request->user()->isAdmin()
            ? $request->input('idt_associacao')
            : $request->user()->membro?->idt_associacao;

        try {
            $ofx = $this->parser->processar($caminhoFull, $arquivo->getClientOriginalName(), $idtAssociacao);

            Storage::disk('local')->delete($caminho);

            return redirect()
                ->route('dashboard', ['ofx' => $ofx->idt_ofx])
                ->with('success', "Arquivo processado com sucesso! {$ofx->qtd_transacao} transações importadas.");

        } catch (\Exception $e) {
            Storage::disk('local')->delete($caminho);

            return redirect()
                ->back()
                ->with('error', 'Erro ao processar arquivo: '.$e->getMessage());
        }
    }
}
