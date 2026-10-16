<?php

namespace App\Http\Controllers\Api\V1\Create;

use App\Http\Controllers\Controller;
use App\Services\Create\TemplateLibrary;
use Illuminate\Http\Request;

/** Templates (owner, 2026-10-16): the page, a template, "Make mine", and staff's "Add to templates". */
class TemplateController extends Controller
{
    public function __construct(private TemplateLibrary $library) {}

    public function index(Request $r)
    {
        return response()->json(['data' => $this->library->page($r->user())]);
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->library->show($r->user(), $id)]);
    }

    /** "Make mine": a new conversation from the template, and the brief to send once the user's photos are attached. */
    public function use(Request $r, string $id)
    {
        $input = $r->validate(['product' => 'required|string|max:2000', 'match' => 'sometimes|in:'.implode(',', TemplateLibrary::MATCHES),
            'aspect_ratio' => 'sometimes|in:9:16,16:9,1:1,4:5', 'idempotency_key' => 'required|string|max:128']);
        return response()->json(['data' => $this->library->use($r->user(), $id, $input)], 201);
    }

    /** What "Add to templates" starts from (staff): the version's parts and the defaults. */
    public function draft(Request $r, string $conversationId, string $revisionId)
    {
        return response()->json(['data' => $this->library->draft($r->user(), $conversationId, $revisionId)]);
    }

    public function store(Request $r)
    {
        $input = $r->validate(self::fields(true) + ['conversation_id' => 'required|uuid', 'revision_id' => 'required|uuid']);
        return response()->json(['data' => $this->library->add($r->user(), $input['conversation_id'], $input['revision_id'],
            array_intersect_key($input, self::fields(true)))], 201);
    }

    public function update(Request $r, string $id)
    {
        return response()->json(['data' => $this->library->update($r->user(), $id, $r->validate(self::fields(false)))]);
    }

    public function destroy(Request $r, string $id)
    {
        $this->library->archive($r->user(), $id);
        return response()->json(['data' => ['archived' => true]]);
    }

    private static function fields(bool $new): array
    {
        $req = $new ? 'required' : 'sometimes|required';
        return ['title' => $req.'|string|max:120', 'category' => $req.'|in:'.implode(',', array_keys(TemplateLibrary::CATEGORIES)),
            'description' => 'sometimes|nullable|string|max:600', 'ask_hint' => 'sometimes|nullable|string|max:300', 'style' => 'sometimes|nullable|string|max:60'];
    }
}
