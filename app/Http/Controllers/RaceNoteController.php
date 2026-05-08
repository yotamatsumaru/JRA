<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Race;
use App\Models\RaceNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RaceNoteController extends Controller
{
    public function index(Request $request): View
    {
        $notes = $request->user()->notes()
            ->with(['race.venue', 'horse'])
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('notes.index', compact('notes'));
    }

    public function create(Request $request): View
    {
        $races = Race::with('venue')->orderByDesc('race_date')->limit(50)->get();
        $horses = Horse::orderBy('name')->limit(200)->get();
        return view('notes.create', compact('races', 'horses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'race_id' => ['nullable', 'exists:races,id'],
            'horse_id' => ['nullable', 'exists:horses,id'],
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string'],
            'tag' => ['nullable', 'string', 'max:50'],
        ]);
        $validated['user_id'] = $request->user()->id;
        RaceNote::create($validated);
        return redirect()->route('notes.index')->with('status', 'メモを保存しました');
    }

    public function edit(RaceNote $note): View
    {
        $this->authorizeUser($note);
        $races = Race::with('venue')->orderByDesc('race_date')->limit(50)->get();
        $horses = Horse::orderBy('name')->limit(200)->get();
        return view('notes.edit', compact('note', 'races', 'horses'));
    }

    public function update(Request $request, RaceNote $note): RedirectResponse
    {
        $this->authorizeUser($note);
        $validated = $request->validate([
            'race_id' => ['nullable', 'exists:races,id'],
            'horse_id' => ['nullable', 'exists:horses,id'],
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string'],
            'tag' => ['nullable', 'string', 'max:50'],
        ]);
        $note->update($validated);
        return redirect()->route('notes.index')->with('status', 'メモを更新しました');
    }

    public function destroy(RaceNote $note): RedirectResponse
    {
        $this->authorizeUser($note);
        $note->delete();
        return redirect()->route('notes.index')->with('status', 'メモを削除しました');
    }

    private function authorizeUser(RaceNote $note): void
    {
        abort_unless($note->user_id === auth()->id(), 403);
    }
}
