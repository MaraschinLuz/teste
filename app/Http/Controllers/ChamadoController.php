<?php

namespace App\Http\Controllers;

use App\Models\Chamado;
use App\Models\Equipe;
use App\Models\Log;
use App\Models\Mensagem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\Anexo;

class ChamadoController extends Controller
{
    use AuthorizesRequests;

    /**
     * Exibe a lista de chamados do usuário.
     */
    public function index(): View
    {
        if (Auth::user()->role === 'tecnica') {
            $chamados = Chamado::with('equipe')->latest()->paginate(10);
        } else {
            $chamados = Chamado::with('equipe')
                ->where('user_id', Auth::id())
                ->latest()
                ->paginate(10);
        }

        if ($user->equipe_id) {
            $chamados->where('equipe_id', $user->equipe_id);
        }
    
        return view('chamados.index', [
            'chamados' => $chamados->latest()->paginate(10),
        ]);

        return view('chamados.index', compact('chamados'));
    }

    /**
     * Exibe o formulário para criar um novo chamado.
     */
    public function create(): View
    {
        $equipes = Equipe::all();
        return view('chamados.create', compact('equipes'));
    }

    /**
     * Salva um novo chamado no banco de dados.
     */
    public function store(Request $request): RedirectResponse
    {   
        $validated = $request->validate([
            'titulo' => 'required|string|max:255',
            'descricao' => 'required|string',
            'prioridade' => 'required|in:baixa,media,alta',
            'equipe_id' => 'nullable|exists:equipes,id',
            'arquivos.*' => 'nullable|file|max:5120', // até 5MB por arquivo
        ]);

        $chamado = Chamado::create([
            'user_id' => Auth::id(),
            'titulo' => $validated['titulo'],
            'descricao' => $validated['descricao'],
            'prioridade' => $validated['prioridade'],
            'equipe_id' => $validated['equipe_id'] ?? null,
            'status' => 'aberto',
        ]);

        // Verifica se há múltiplos arquivos enviados
        if ($request->hasFile('arquivos')) {
            foreach ($request->file('arquivos') as $arquivo) {
                $path = $arquivo->store('anexos', 'public');
                $chamado->anexos()->create(['arquivo' => $path]);
            }
        }

        Log::create([
            'user_id' => auth()->id(),
            'acao' => 'Criou um chamado',
            'detalhes' => 'Título: ' . $chamado->titulo,
        ]);

        return redirect()->route('chamados.index')->with('success', 'Chamado criado com sucesso!');
    }

    /**
     * Exibe os detalhes de um chamado específico.
     */
    public function show(Chamado $chamado): View
    {
        $this->authorize('view', $chamado);
        // carregar mensagens com usuário para evitar N+1
        $chamado->load(['mensagens.user', 'equipe']);
        $this->authorize('view', $chamado);
        $chamado->load('anexos');
        return view('chamados.show', compact('chamado'));
    }

    /**
     * Exibe o formulário de edição de um chamado.
     */
    public function edit(Chamado $chamado): View
    {
        $this->authorize('update', $chamado);
        $equipes = Equipe::all();
        return view('chamados.edit', compact('chamado', 'equipes'));
    }

    /**
     * Atualiza um chamado existente.
     */
    public function update(Request $request, Chamado $chamado): RedirectResponse
    {
        $this->authorize('update', $chamado);

        $rules = [
            'titulo' => 'required|string|max:255',
            'descricao' => 'required|string',
            'prioridade' => 'required|in:baixa,media,alta',
            'status' => 'required|in:aberto,em andamento,resolvido,fechado',
            'equipe_id' => 'nullable|exists:equipes,id',
        ];

        if (Auth::user()->role !== 'tecnica') {
            $rules['prioridade'] = ['required', Rule::in([$chamado->prioridade])];
            $rules['status'] = ['required', Rule::in([$chamado->status])];
        }

        $validated = $request->validate($rules);

        $chamado->update($validated);

        Log::create([
            'user_id' => auth()->id(),
            'acao' => 'Atualizou um chamado',
            'detalhes' => 'Título: ' . $chamado->titulo,
        ]);

        return redirect()->route('chamados.index')->with('success', 'Chamado atualizado com sucesso!');
    }

    /**
     * Remove um chamado.
     */
    public function destroy(Chamado $chamado): RedirectResponse
    {
        $this->authorize('delete', $chamado);

        $titulo = $chamado->titulo;
        $chamado->delete();

        Log::create([
            'user_id' => auth()->id(),
            'acao' => 'Excluiu um chamado',
            'detalhes' => 'Título: ' . $titulo,
        ]);

        return redirect()->route('chamados.index')->with('success', 'Chamado excluído com sucesso!');
    }

    /**
     * Salva uma nova mensagem (comentário) no histórico do chamado.
     */
    public function storeMensagem(Request $request, Chamado $chamado): RedirectResponse
    {
        $this->authorize('view', $chamado);

        $validated = $request->validate([
            'mensagem' => 'required|string|max:1000',
        ]);

        $chamado->mensagens()->create([
            'user_id' => Auth::id(),
            'mensagem' => $validated['mensagem'],
        ]);

        Log::create([
            'user_id' => auth()->id(),
            'acao' => 'Adicionou uma mensagem ao chamado',
            'detalhes' => 'Chamado: ' . $chamado->titulo,
        ]);

        return redirect()->route('chamados.show', $chamado)->with('success', 'Mensagem enviada com sucesso!');
    }

    public function adicionarAnexo(Request $request, Chamado $chamado)
    {
        $request->validate([
            'arquivo' => 'required|file|max:5120', // 5MB
        ]);

        if ($request->hasFile('arquivo')) {
            $path = $request->file('arquivo')->store('anexos', 'public');

            $chamado->anexos()->create([
                'arquivo' => $path,
            ]);
        }

        return redirect()->route('chamados.show', $chamado)->with('success', 'Anexo adicionado com sucesso!');
    }
}