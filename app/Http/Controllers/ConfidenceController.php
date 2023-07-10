<?php

namespace App\Http\Controllers;

use App\Http\Resources\StatusResource;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Completions\CreateResponse;
use Illuminate\Support\Facades\Http;

class ConfidenceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function calculateConfidence(Request $request)
    {
        // Get sizes of particular SKU
        $sizes = $this->getProductSizes();

        // Connect To OpenAI
        // $chat = $this->getOpenAiResponse();

        $confidenceScore = rand(0, 1000);

        return response()->json([
            'data' => [
                // 'chat' => $chat,
                'abc' => 123,
            ],
        ]);
    }

    private function getProductSizes()
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Cookie' => '_cdn=cf',
        ])->get('https://api.staging.sg.zalora.net/v1/products/abercrombie-fitch-easy-throw-on-swing-dress-pink-699224.html/size')->json();

        return $response['data'];
    }

    private function getOpenAiResponse()
    {
        $chat = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            "messages" => [
                [
                    "role" => "system",
                    "content" => "You are helping the user find the best fit. You aim to give the most precise fit"
                ],
                [
                    "role" => "assistant",
                    "content" => "Is this purchase for yourself or someone else?"
                ],
                [
                    "role" => "user",
                    "content" => "Myself"
                ],
                [
                    "role" => "assistant",
                    "content" => "What type of fit do you usually prefer?"
                ],
                [
                    "role" => "user",
                    "content" => "Regular"
                ],
                [
                    "role" => "assistant",
                    "content" => "What size do you usually wear? Or do you hesitate between 2 size?"
                ],
                [
                    "role" => "user",
                    "content" => "M most of the time or S or even L sometimes"
                ]
            ]
        ]);

        $chat->id; // 'chatcmpl-6pMyfj1HF4QXnfvjtfzvufZSQq6Eq'
        $chat->object; // 'chat.completion'
        $chat->created; // 1677701073
        $chat->model; // 'gpt-3.5-turbo-0301'

        foreach ($chat->choices as $result) {
            $result->index; // 0
            $result->message->role; // 'assistant'
            $result->message->content; // '\n\nHello there! How can I assist you today?'
            $result->finishReason; // 'stop'
        }

        $chat->usage->promptTokens; // 9,
        $chat->usage->completionTokens; // 12,
        $chat->usage->totalTokens; // 21

        return $chat;
    }
}
