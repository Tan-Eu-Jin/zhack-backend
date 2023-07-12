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
            'selected_size_system' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => [
                    'errors' => $validator->errors(),
                ]
            ], 401);
        }

        $debugType = $request->debug_type;
        $configSku = $request->config_sku;
        $selectedSizeSystem = $request->selected_size_system;

        // Get sizes of particular product
        $sizes = $this->getProductSizes($configSku);

        $latestMessage = $request->messages;
        $latestMessage = end($latestMessage);

        $responseMessages = $request->messages;
        if ($latestMessage['content'] === 'Yes, add this item to my bag') {
            array_push($responseMessages, [
                'role' => 'assistant',
                'content' => 'Item has been added to your cart! Have a good day!',
            ]);

            return response()->json([
                'data' => [
                    'messages' => $responseMessages,
                ]
            ]);
        }

        // Check for "Item has been added"
        foreach ($responseMessages as $index => $message) {
            if ($message['role'] === 'assistant' && $message['content'] === 'Item has been added to your cart! Have a good day!') {
                $responseMessages = array_splice($responseMessages, 0, $index + 1);
                return response()->json([
                    'data' => [
                        'messages' => $responseMessages,
                    ]
                ]);
            }
        }

        // Connect To OpenAI
        $chat = $this->getOpenAiResponse($request->messages, $sizes);
        array_push($responseMessages, $chat['choices'][0]['message']);
        $responseMessages = $this->processResponseMessages($responseMessages, $configSku, $selectedSizeSystem);

        return response()->json([
            'data' => [
                'messages' => $responseMessages,
            ]
        ]);
    }

    private function getProductDetails($configSku)
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Cookie' => '_cdn=cf',
        ])->get('https://api.staging.sg.zalora.net/v1/products/' . $configSku . '/details')->json();

        return $response['data'];
    }

    private function getProductSizes($configSku)
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Cookie' => '_cdn=cf',
        ])->get('https://api.staging.sg.zalora.net/v1/products/' . $configSku . '/size')->json();

        return $response['data'];
    }

    private function getQuestionsSetsForOpenAi($purchaser)
    {
        return ($purchaser === 'Themselves') ?  [
            "Second, you need to ask the user which body size resembles them the most. The body size options are Inverted Triangle, Pear, Hourglass, Round, Rectangle.",
            "Thrid, you need to ask the user their fit preference. The fit preferences are On the loose side, Regular, On the tight side.",
            "Fourth, you need to ask the user what are the usual size they usually wear.",
        ] : [
            "You need to ask the user if they know the size of the person they are buying for OR they can provide two of the three measurements of hips, waist or the bust.",
        ];
    }

    private function getOpenAiResponse($messages, $sizes)
    {
        $allMessages = [
            [
                "role" => "system",
                'content' => '""" Please act as a perfect fit assistant and help the user find the best fit. You need to ask the users a few questions before determining the best fit. Ask the questions provided below, in order. Display it to the user and wait for a reply before proceeding to the next. Once all the questions are answered, match it agaisnt the available sizes and provide the best fit. """ """ First, you need to ask the user if they are purchasing this for themselves or for others. """ """ Here are the set of questions if the user is buying for themselves. Second, you need to ask the user which body size resembles them the most. The body size options are Inverted Triangle, Pear, Hourglass, Round, Rectangle. Thrid, you need to ask the user their fit preference. The fit preferences are On the loose side, Regular, On the tight side. Fourth, you need to ask the user what are the usual size they usually wear. """ """ Here are the set of questions if the user is buy for someone else. Second, You need to ask the user if they know the size of the person they are buying for OR they can provide two of the three measurements of hips, waist or the bust. If you cannot find a size based on these information, end the suggestion. """ """ IT IS CRUCIAL THAT YOU ASK THE QUESTIONS ONE BY ONE AND WAIT FOR A REPLY BEFORE ASKING THE NEXT. """',
            ],
            [
                "role" => "system",
                'content' => '""" Here is the size chart. ' . $sizes['SizeChart'] . ' """',
            ],
            [
                "role" => "system",
                'content' => '""" When you respond to me, respond to me in a point form where each question and answer is separated by a \':\' """',
            ],
            [
                "role" => "system",
                'content' => '"""The best match for your measurements is size S""" """The best match for your measurements is size M""" is the only format you should suggest the size in.',
            ],
        ];

        foreach ($messages as $message) {
            array_push($allMessages, [
                'role' => $message['role'],
                'content' => $message['content'],
            ]);
        };

        $chat = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            "messages" => $allMessages,
        ]);

        $chat->id;
        $chat->object;
        $chat->created;
        $chat->model;

        foreach ($chat->choices as $result) {
            $result->index;
            $result->message->role;
            $result->message->content;
            $result->finishReason;
        }

        $chat->usage->promptTokens;
        $chat->usage->completionTokens;
        $chat->usage->totalTokens;

        return $chat;
    }

    private function processResponseMessages($responseMessages, $configSku, $selectedSizeSystem)
    {
        foreach ($responseMessages as $index => $message) {
            $role = $message['role'];
            $content = $message['content'];

            if (
                $role === 'assistant' &&
                (str_contains($content, 'Inverted Triangle') || str_contains($content, 'Pear') || str_contains($content, 'Hourglass') || str_contains($content, 'Round') || str_contains($content, 'Rectangle')) &&
                !str_contains($content, 'Based on your response')
            ) {
                $responseMessages[$index]['body_shapes'] = $this->getBodyShapes();
            }

            if (
                $role === 'assistant' &&
                (str_contains(strtolower($content), 'on the loose side') || str_contains(strtolower($content), 'regular') || str_contains(strtolower($content), 'on the tight side')) &&
                !str_contains($content, 'Based on your response')
            ) {
                $responseMessages[$index]['fit_types'] = $this->getFitTypes();
            }

            // Determine if a response is found
            if ($role === 'assistant' && str_contains($content, 'Based on your')) {
                $sizeByOpenAi = substr($content, -2, -1);

                $details = $this->getProductDetails($configSku);
                $simpleSku = '';
                foreach ($details['Simples'] as $simple) {
                    if (strtolower($simple['Size']) === strtolower($sizeByOpenAi)) {
                        $simpleSku = $simple['SimpleSku'];
                        break;
                    }
                }

                array_push($responseMessages, [
                    "role" => "assistant",
                    'content' => 'Would you like to add this item to your bag?',
                    'best_fit' => [
                        'size' => $sizeByOpenAi,
                        'size_system' => $selectedSizeSystem,
                        'simple_sku' => $simpleSku,
                    ],
                ]);
            }
        }

        return $responseMessages;
    }

    private function getMessages($debugType, $selectedSizeSystem)
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
                'fit_type' => $this->getFitTypes(),
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
                    'size_system' => $selectedSizeSystem,
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
                    'size_system' => $selectedSizeSystem,
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
                    'size_system' => $selectedSizeSystem,
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
                'body_shapes' => $this->getBodyShapes(),
            ]);
        }

        if ($debugType === 'second_level_found_match_wait_bag_reply') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes(),
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
                    'size_system' => $selectedSizeSystem,
                    'simple_sku' => 'This-is-a-placeholder',
                ],
            ]);
        }

        if ($debugType === 'second_level_found_match_add_to_bag') {
            array_push($messages, [
                "role" => "assistant",
                'content' => 'For a better recommendation, please tell use more about your body shape. Select one of the options below.',
                'body_shapes' => $this->getBodyShapes(),
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
                    'size_system' => $selectedSizeSystem,
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
                'body_shapes' => $this->getBodyShapes(),
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
                    'size_system' => $selectedSizeSystem,
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

    private function getFitTypes()
    {
        return [
            [
                'caption' => 'On the loose side',
            ],
            [
                'caption' => 'Regular',
            ],
            [
                'caption' => 'On the tight side',
            ],
        ];
    }

    private function getBodyShapes()
    {
        return [
            [
                'img_src' => 'https://i.postimg.cc/GHLpCLJG/image-triangle.png',
                'caption' => 'Inverted Triangle',
            ],
            [
                'img_src' => 'https://i.postimg.cc/tZGXmp41/image-pear.png',
                'caption' => 'Pear',
            ],
            [
                'img_src' => 'https://i.postimg.cc/k2DX2TH5/image-hourglass.png',
                'caption' => 'Hourglass',
            ],
            [
                'img_src' => 'https://i.postimg.cc/bGtyTspc/image-round.png',
                'caption' => 'Round',
            ],
            [
                'img_src' => 'https://i.postimg.cc/75cP0gvn/image-rectangle.png',
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
