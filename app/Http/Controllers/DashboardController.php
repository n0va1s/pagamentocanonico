<?php

namespace App\Http\Controllers;

use App\Models\Ofx;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $idtOfx = $request->query('ofx');

        $importacoes = Ofx::latest()->get();

        $importacaoSelecionada = $idtOfx
            ? Ofx::with('resumos')->findOrFail($idtOfx)
            : $importacoes->first();

        $dadosDashboard = [];
        $mesesDisponiveis = collect();

        if ($importacaoSelecionada) {
            $resumos = $importacaoSelecionada->resumos()
                ->orderBy('num_ano')
                ->orderBy('num_mes')
                ->get();

            // Meses únicos disponíveis no período
            $mesesDisponiveis = $resumos
                ->unique(fn ($r) => $r->num_ano.'-'.str_pad($r->num_mes, 2, '0', STR_PAD_LEFT))
                ->sortBy(['num_ano', 'num_mes'])
                ->values();

            // Agrupa por pessoa
            $porPessoa = $resumos->groupBy('nom_pessoa');

            foreach ($porPessoa as $nomePessoa => $resumosPessoa) {
                $linha = [
                    'nome' => $nomePessoa,
                    'meses' => [],
                    'total' => 0,
                    'situacao' => 'Adimplente',
                ];

                foreach ($mesesDisponiveis as $mesRef) {
                    $resumoMes = $resumosPessoa->firstWhere(
                        fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes
                    );

                    $valor = $resumoMes ? (float) $resumoMes->val_total : 0;
                    $linha['meses'][] = $valor;
                    $linha['total'] += $valor;

                    if ($valor <= 0) {
                        $linha['situacao'] = 'Inadimplente';
                    }
                }

                $dadosDashboard[] = $linha;
            }
        }

        return view('dashboard', compact(
            'importacoes',
            'importacaoSelecionada',
            'dadosDashboard',
            'mesesDisponiveis'
        ));
    }
}
