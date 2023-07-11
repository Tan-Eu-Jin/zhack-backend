<?php

namespace App\Http\Controllers;

use App\Http\Resources\StatusResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Completions\CreateResponse;

class BestFitController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function getBestFit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'config_sku' => 'required|max:255',
            'user_id' => 'sometimes',
            'messages' => 'required|array',
            'messages.*.role' => 'required|in:user,assistant,system',
            'messages.*.content' => 'required',
            'selected_body_shape' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => [
                    'errors' => $validator->errors(),
                ]
            ], 401);
        }
        $debugType = $request->debug_type;

        // Get sizes of particular SKU
        // $sizes = $this->getProductSizes();

        // Connect To OpenAI
        // $chat = $this->getOpenAiResponse();

        // Final Best Fit
        $confidenceScore = rand(0, 1000);

        return response()->json([
            'data' => [
                'messages' => $this->getMessages($debugType),
            ]
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
                    'content' => 'You are helping the user find the best fit. You aim to give the most precise fi',
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

    private function getMessages($debugType)
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => 'Hi there. Our perfect Fit Assistant will help you get a personalised size recommendation.',
            ],
            [
                'role' => 'assistant',
                'content' => 'I get smarter the more you tell me, so I’m going to ask a few questions that will help me help you.',
            ],
            [
                'role' => 'assistant',
                'content' => 'Is this purchase for yourself or someone else?',
            ],
            [
                'role' => 'user',
                'content' => 'Myself',
            ],
            [
                'role' => 'assistant',
                'content' => 'What type of fit do you usually prefer?',
            ],
            [
                'role' => 'user',
                'content' => 'Regular',
            ],
            [
                'role' => 'assistant',
                'content' => 'What size do you usually wear? Or do you hesitate between 2 size?',
            ],
            [
                'role' => 'user',
                'content' => 'M most of the time or S or even L sometimes',
            ]
        ];

        if ($debugType === 'first_level_found_match_wait_bag_reply') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);
        }

        if ($debugType === 'first_level_found_match_add_to_bag') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'Yes, add this item to my bag',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Great News! This item has been added to your bag.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Is there anything else I can help you with?',
            ]);
        }

        if ($debugType === 'first_level_found_match_show_simillar_items') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'No, see similar items',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'We found some similiar options you might like:',
                'recomended_products' => $this->getRecomendedProducts(),
            ]);
        }

        if ($debugType === 'second_level_found_match_wait_shape_reply') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes($debugType),
            ]);
        }

        if ($debugType === 'second_level_found_match_wait_bag_reply') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes($debugType),
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'My body shape closest resembles the body shape “Pear”.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);
        }

        if ($debugType === 'second_level_found_match_add_to_bag') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes($debugType),
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'My body shape closest resembles the body shape “Pear”.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'Yes, add this item to my bag',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Great News! This item has been added to your bag.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Is there anything else I can help you with?',
            ]);
        }

        if ($debugType === 'second_level_found_show_simillar_items') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes($debugType),
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'My body shape closest resembles the body shape “Pear”.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Based on your fit preference, the item measurements and your past purchases, size M will be a better fit for you for this item.',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'Would you like to add this item to your bag?',
                'best_fit' => [
                    'size' => 'M',
                    'size_system' => 'Intl.',
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);

            array_push($messages, [
                "role" => "user",
                'content' => 'No, see similar items',
            ]);

            array_push($messages, [
                "role" => "assistant",
                'content' => 'We found some similiar options you might like:',
                'recomended_products' => $this->getRecomendedProducts(),
            ]);
        }

        return $messages;
    }

    private function getBodyShapes($debugType)
    {
        return [
            [
                'img_src' => 'https://placehold.co/270x480?text=Inverted+Triangle&font=lato',
                'caption' => 'Inverted Triangle',
            ],
            [
                'img_src' => 'https://placehold.co/270x480?text=Pear&font=lato',
                'caption' => 'Pear',
            ],
            [
                'img_src' => 'https://placehold.co/270x480?text=Hourglass&font=lato',
                'caption' => 'Hourglass',
            ],
            [
                'img_src' => 'https://placehold.co/270x480?text=Round&font=lato',
                'caption' => 'Round',
            ],
            [
                'img_src' => 'https://placehold.co/270x480?text=Rectangle&font=lato',
                'caption' => 'Rectangle',
            ],
        ];
    }

    private function getRecomendedProducts()
    {
        return [
            [
                'ClickUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&ct=https%3A%2F%2Fwww.zalora.sg%2Fp%2Fprincess-polly-floreto-mini-dress-black-black-3096168&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=AB833AAD286C10GS&pa=65293&pos=0&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'ImageUrl' => 'https://dynamic.zacdn.com/FVI4GHTg3VMhgATazxa9oyVptAI=/filters:quality(70):format(webp)/https://static-sg.zacdn.com/p/princess-polly-4720-8616903-1.jpg',
                'TrackingUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=AB833AAD286C10GS&pa=65293&pos=0&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'Sku' => 'AB833AAD286C10GS',
                'ProductName' => 'Floreto Mini Dress Black',
                'BrandName' => 'Princess Polly',
                'PriceCents' => '',
                'PriceFormatted' => 'S$ 85.00',
                'SpecialPriceFormatted' => 'S$ 85.00',
                'Categories' => null,
                'MarkdownLabel' => '',
                'Price' => 85,
                'SpecialPrice' => 85,
                'ProductUrl' => 'https://www.zalora.sg/p/princess-polly-floreto-mini-dress-black-black-3096168',
                'ProductSource' => 'RR',
            ],
            [
                'ClickUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&ct=https%3A%2F%2Fwww.zalora.sg%2Fp%2Fhm-smock-topped-dress-white-3159219&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=33B4EAAC668B39GS&pa=65293&pos=1&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'ImageUrl' => 'https://dynamic.zacdn.com/IxN8ftPWzDxjaNXeMIE55Fu8fHI=/filters:quality(70):format(webp)/https://static-sg.zacdn.com/p/hm-7243-9129513-1.jpg',
                'TrackingUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=33B4EAAC668B39GS&pa=65293&pos=1&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'Sku' => '33B4EAAC668B39GS',
                'ProductName' => 'Smock-topped dress',
                'BrandName' => 'H&M',
                'PriceCents' => '',
                'PriceFormatted' => 'S$ 19.95',
                'SpecialPriceFormatted' => 'S$ 19.95',
                'Categories' => null,
                'MarkdownLabel' => '',
                'Price' => 19.95,
                'SpecialPrice' => 19.95,
                'ProductUrl' => 'https://www.zalora.sg/p/hm-smock-topped-dress-white-3159219',
                'ProductSource' => 'RR',
            ],
            [
                'ClickUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&ct=https%3A%2F%2Fwww.zalora.sg%2Fp%2Fmango-striped-jersey-dress-blue-3198038&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=90FDDAA187FC93GS&pa=65293&pos=2&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'ImageUrl' => 'https://dynamic.zacdn.com/Z-F7_xIixzTfJKu-Y8txWyBX5m4=/filters:quality(70):format(webp)/https://static-sg.zacdn.com/p/mango-4999-8308913-1.jpg',
                'TrackingUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=90FDDAA187FC93GS&pa=65293&pos=2&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'Sku' => '90FDDAA187FC93GS',
                'ProductName' => 'Striped Jersey Dress',
                'BrandName' => 'Mango',
                'PriceCents' => '',
                'PriceFormatted' => 'S$ 69.90',
                'SpecialPriceFormatted' => 'S$ 69.90',
                'Categories' => null,
                'MarkdownLabel' => '',
                'Price' => 69.9,
                'SpecialPrice' => 69.9,
                'ProductUrl' => 'https://www.zalora.sg/p/mango-striped-jersey-dress-blue-3198038',
                'ProductSource' => 'RR',
            ],
            [
                'ClickUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&ct=https%3A%2F%2Fwww.zalora.sg%2Fp%2Fmango-fringed-shift-dress-black-3185911&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=0A5D8AADF769BFGS&pa=65293&pos=3&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'ImageUrl' => 'https://dynamic.zacdn.com/g7crc0YQhZ4y9Mj0FkmdJ8jCUNU=/filters:quality(70):format(webp)/https://static-sg.zacdn.com/p/mango-0192-1195813-1.jpg',
                'TrackingUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=0A5D8AADF769BFGS&pa=65293&pos=3&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'Sku' => '0A5D8AADF769BFGS',
                'ProductName' => 'Fringed Shift Dress',
                'BrandName' => 'Mango',
                'PriceCents' => '',
                'PriceFormatted' => 'S$ 129.90',
                'SpecialPriceFormatted' => 'S$ 129.90',
                'Categories' => null,
                'MarkdownLabel' => '',
                'Price' => 129.9,
                'SpecialPrice' => 129.9,
                'ProductUrl' => 'https://www.zalora.sg/p/mango-fringed-shift-dress-black-3185911',
                'ProductSource' => 'RR',
            ],
            [
                'ClickUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&ct=https%3A%2F%2Fwww.zalora.sg%2Fp%2Fmango-tropical-shirt-dress-black-3197956&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=F8859AA7E54DD5GS&pa=65293&pos=4&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'ImageUrl' => 'https://dynamic.zacdn.com/q5dDTDAcV-FRfAxNKQTYe0v8dcY=/filters:quality(70):format(webp)/https://static-sg.zacdn.com/p/mango-2467-6597913-1.jpg',
                'TrackingUrl' => 'https://recs.richrelevance.com/rrserver/apiclick?a=212ef076ae59b7c9&cak=bfe58b3a1359c422&channelId=bfe58b3a1359c422&mvtId=61383%7C61368%7C61371%7C60619&mvtTs=1689027430928&p=F8859AA7E54DD5GS&pa=65293&pos=4&pti=1&qsgs=33824%7C27020%7C26902%7C27631%7C27630&s=qkPcZi48U4g9Nc4NT0zdQNk4KnjdMSSA&stid=31869&vg=dfc77839-751e-463d-c44c-8f625076a242',
                'Sku' => 'F8859AA7E54DD5GS',
                'ProductName' => 'Tropical Shirt Dress',
                'BrandName' => 'Mango',
                'PriceCents' => '',
                'PriceFormatted' => 'S$ 69.90',
                'SpecialPriceFormatted' => 'S$ 69.90',
                'Categories' => null,
                'MarkdownLabel' => '',
                'Price' => 69.9,
                'SpecialPrice' => 69.9,
                'ProductUrl' => 'https://www.zalora.sg/p/mango-tropical-shirt-dress-black-3197956',
                'ProductSource' => 'RR',
            ],
        ];
    }
}
