<?php

namespace App\Http\Controllers;

use App\Collection\Models\Source;
use App\Collection\Models\Status;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The internal dictionaries: sources, storage places, tags and statuses.
 *
 * One controller rather than four near-identical ones — they differ only in
 * which extra fields they carry. Answers JSON so the settings page can edit a
 * row without losing the rest of the form.
 */
class DictionaryController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const KINDS = [
        'sources' => Source::class,
        'storages' => Storage::class,
        'tags' => Tag::class,
        'statuses' => Status::class,
    ];

    /** Bootstrap contextual classes, the only colours a badge may take. */
    public const COLORS = [
        'primary', 'secondary', 'success', 'danger',
        'warning', 'info', 'light', 'dark',
    ];

    public function store(Request $request, string $kind): JsonResponse
    {
        $model = $this->modelFor($kind);

        $row = $model::create($this->validated($request, $kind) + [
            'sort' => ($model::max('sort') ?? 0) + 10,
        ]);

        return response()->json(['row' => $row->fresh()]);
    }

    public function update(Request $request, string $kind, int $id): JsonResponse
    {
        $row = $this->modelFor($kind)::findOrFail($id);

        $row->update($this->validated($request, $kind));

        return response()->json(['row' => $row->fresh()]);
    }

    public function destroy(string $kind, int $id): JsonResponse
    {
        $row = $this->modelFor($kind)::findOrFail($id);

        // Box and Instructions are part of how a set is described; removing
        // them would leave existing copies referring to nothing.
        if ($kind === 'statuses' && $row->is_system) {
            throw ValidationException::withMessages([
                'id' => __('app.dictionaries.system_undeletable'),
            ]);
        }

        // A source or storage is referenced by entries with no cascade, so the
        // database would refuse anyway; refusing here says why.
        foreach ([['sources', 'source_id'], ['storages', 'storage_id']] as [$referenced, $column]) {
            if ($kind === $referenced) {
                $inUse = DB::table('collection_entries')->where($column, $row->id)->count();

                if ($inUse > 0) {
                    throw ValidationException::withMessages([
                        'id' => __('app.dictionaries.in_use', ['count' => $inUse]),
                    ]);
                }
            }
        }

        $row->delete();

        return response()->json(['deleted' => $id]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $kind): array
    {
        $rules = ['name' => ['required', 'string', 'max:120']];

        if ($kind === 'tags') {
            $rules['color'] = ['required', 'string', 'in:'.implode(',', self::COLORS)];
            $rules['show_in_list'] = ['boolean'];
        }

        if (in_array($kind, ['sources', 'storages'], true)) {
            $rules['is_active'] = ['boolean'];
        }

        return $request->validate($rules);
    }

    /** @return class-string<Model> */
    private function modelFor(string $kind): string
    {
        return self::KINDS[$kind] ?? abort(404);
    }
}
