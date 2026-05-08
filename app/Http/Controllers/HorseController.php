<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HorseController extends Controller
{
    public function index(Request $request): View
    {
        $query = Horse::query()->withCount('results');

        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', "%{$kw}%")
                  ->orWhere('father', 'like', "%{$kw}%")
                  ->orWhere('mother', 'like', "%{$kw}%");
            });
        }

        if ($request->filled('sex')) {
            $query->where('sex', $request->sex);
        }

        $horses = $query->orderBy('name')->paginate(40)->withQueryString();

        return view('horses.index', compact('horses'));
    }

    public function create(): View
    {
        return view('horses.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'name_kana' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'in:牡,牝,セ'],
            'birthday' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'max:20'],
            'father' => ['nullable', 'string', 'max:50'],
            'mother' => ['nullable', 'string', 'max:50'],
            'mother_father' => ['nullable', 'string', 'max:50'],
            'owner' => ['nullable', 'string', 'max:100'],
            'breeder' => ['nullable', 'string', 'max:100'],
        ]);
        $horse = Horse::create($validated);
        return redirect()->route('horses.show', $horse)->with('status', '馬を登録しました');
    }

    public function show(Horse $horse): View
    {
        $horse->load(['results.race.venue', 'results.jockey']);
        $summary = $horse->summary();

        // 距離別成績
        $byDistance = $horse->results()
            ->whereNotNull('finish_position_int')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->selectRaw('races.distance, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins')
            ->groupBy('races.distance')
            ->orderBy('races.distance')
            ->get();

        // 競馬場別成績
        $byVenue = $horse->results()
            ->whereNotNull('finish_position_int')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->join('venues', 'venues.id', '=', 'races.venue_id')
            ->selectRaw('venues.name, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins')
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('cnt')
            ->get();

        return view('horses.show', compact('horse', 'summary', 'byDistance', 'byVenue'));
    }

    public function edit(Horse $horse): View
    {
        return view('horses.edit', compact('horse'));
    }

    public function update(Request $request, Horse $horse): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'name_kana' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'in:牡,牝,セ'],
            'birthday' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'max:20'],
            'father' => ['nullable', 'string', 'max:50'],
            'mother' => ['nullable', 'string', 'max:50'],
            'mother_father' => ['nullable', 'string', 'max:50'],
            'owner' => ['nullable', 'string', 'max:100'],
            'breeder' => ['nullable', 'string', 'max:100'],
        ]);
        $horse->update($validated);
        return redirect()->route('horses.show', $horse)->with('status', '馬情報を更新しました');
    }

    public function destroy(Horse $horse): RedirectResponse
    {
        $horse->delete();
        return redirect()->route('horses.index')->with('status', '馬を削除しました');
    }
}
