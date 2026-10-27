<?php

namespace App\Http\Controllers;

use App\Models\CouncilRoom;
use App\Services\CouncilService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CouncilRoomController extends Controller
{
    public function store(Request $request, CouncilService $council): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:20000'],
            'room_id' => ['nullable', 'uuid', 'exists:council_rooms,id'],
        ]);

        $room = $council->ask($data['question'], isset($data['room_id']) ? CouncilRoom::find($data['room_id']) : null);

        return response()->json($room, 201);
    }

    public function show(CouncilRoom $councilRoom): JsonResponse
    {
        return response()->json($councilRoom->load(['messages' => fn ($query) => $query->oldest('id')]));
    }

    public function destroy(CouncilRoom $councilRoom): Response
    {
        $councilRoom->delete();

        return response()->noContent();
    }
}
