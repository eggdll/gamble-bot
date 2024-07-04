<?php

include __DIR__.'/vendor/autoload.php';
include __DIR__.'/Database.php';

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Embed\Field;
use Discord\Parts\User\Activity;
use Discord\Parts\User\User;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;

const COMMAND_PREFIX = "g.";
const CURRENCY = "crackers";
const COLOR = 8399514;
const BANK_LOAN_LIMIT = 1000;
$TOKEN = getenv("DSCTOKEN");
$GLOBALS['token'] = $TOKEN;
$db = Database::get();

$command = "php -S 127.0.0.1:8000 main.php";

if (isset($_SERVER["REQUEST_URI"])) {
    die("Hello");
}

if (pcntl_fork() == 0) {
    exec($command);
} else {
    echo "Server started in background.\n";
}

$discord = new Discord([
    'token' => $TOKEN,
    'intents' => Intents::getDefaultIntents(),
]);

function handleException($exception) {
    global $db;

    echo "!Important An unexpected exception occurred, saving the db \n";
    Database::get()->save();

    //try {
        throw $exception;
    //} catch (\Throwable $th) {
    //    echo $th->getTraceAsString(), PHP_EOL;
    //}
}

set_exception_handler('handleException');

$winmsg = [
    "cool ig",
    "congrats",
    "wow",
    "awwww",
];

$GLOBALS['winmsg'] = $winmsg;

$losemsg = [
    "aww shucks",
    "99.999999999999% gamblers quit before they hit big",
    "better luck next time!",
    "dont give up yet..",
    "you can be rich if you continue!"
];

$GLOBALS['losemsg'] = $losemsg;

$GLOBALS['upgradeprices'] = [
    1 => 5000,
    2 => 10000,
    3 => 50000,
    6 => 1000000,

];

function makeEmbed($discord, $title, $fields = [], $description = "", $color = COLOR) {
    $embed = new Embed($discord);
    $embed->setTitle($title);
    $embed->setColor(COLOR);
    $embed->setType(Embed::TYPE_RICH);

    foreach ($fields as $field) {
        $embed->addField(new Field($discord, $field));
    }

    if ($description != "") {
        $embed->setDescription($description);
    }
    
    return $embed;
}

function gamble(int $amt, Message $msg) {
    global $discord;

    (int)$gamblerid = (int)$msg->author->id;

    $embed = makeEmbed($discord, "gambling...", [[
        'name' => 'bet',
        'value' => sprintf("**%d** %s", $amt, CURRENCY)
    ]]);
    
    $builder = MessageBuilder::new()
    ->setContent("<@{$gamblerid}>")
    ->addEmbed($embed);

    $msg->reply($builder)->done(function(Message $message) {
        global $discord, $db;

        (int)$gamblerid = (int)$message->referenced_message->author->id;
        $amt = $GLOBALS[$gamblerid]['amt'];

        if ($db->getUserDebt($gamblerid) < 0) {
            $db->setUserDebt($gamblerid, 0);
        }

        if ($amt <= 0) {
            $embed = makeEmbed($discord, "error!", [], "invalid bet", 11867413);
            
            $builder = MessageBuilder::new()
            ->setContent("<@{$gamblerid}>")
            ->addEmbed($embed);

            $message->edit($builder);
        }

        if ($db->getUserBalance($gamblerid) < $amt) {
            $embed = makeEmbed($discord, "error!", [], "not enough crackers", 11867413);
            
            $builder = MessageBuilder::new()
            ->setContent("<@{$gamblerid}>")
            ->addEmbed($embed);

            $message->edit($builder);
        } else {
            $rand = random_int(1, 8);
            $db->removeMoney($gamblerid, $amt);

            if ($rand % 2 == 0) {
                // YOU WON!!!
                $wonamt = $amt * $db->getUserMultiplier($gamblerid);

                $flds = [
                    [
                        'name' => 'spent',
                        'value' => $amt,
                        'inline' => true
                    ],
                    [
                        'name' => 'won',
                        'value' => sprintf("🍪 **%d**", $wonamt),
                        'inline' => true
                    ],
                    [
                        'name' => 'total',
                        'value' => sprintf("🍪 **%d**", $db->getUserBalance($gamblerid) + $wonamt),
                        'inline' => true
                    ]
                ];

                if ($db->getUserDebt($gamblerid) != 0) {
                    $flds[array_key_last($flds)]['value'] = sprintf("🍪 **%d**", ($db->getUserBalance($gamblerid) + $wonamt) - $wonamt * (25 / 100));
                    $flds[] = [
                        'name' => 'debt paid',
                        'value' => sprintf("🏦 **%d**", $wonamt * (25 / 100)),
                        'inline' => true
                    ];
                }
            
                $embed = makeEmbed($discord, "JACKPOT!", $flds, $GLOBALS['winmsg'][array_rand($GLOBALS['winmsg'])], 11867413);
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$gamblerid}>")
                ->addEmbed($embed);
    
                $message->edit($builder);

                $db->addMoney($gamblerid, $wonamt);

                if ($db->getUserDebt($gamblerid) != 0) {
                    $db->decreaseUserDebt($gamblerid, $wonamt * (25 / 100));
                    $db->removeMoney($gamblerid, $wonamt * (25 / 100));
                }
            } else {
                if ($db->getUserDebt($gamblerid) != 0) {
                    $db->setUserDebt($gamblerid, $db->getUserDebt($gamblerid) + $db->getUserBalance($gamblerid) * (25 / 100));
                }

                $embed = makeEmbed($discord, "you lost", [], $GLOBALS['losemsg'][array_rand($GLOBALS['losemsg'])], 11867413);
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$gamblerid}>")
                ->addEmbed($embed);
    
                $message->edit($builder);
            }

            $db->save();
        }
    });

}

$discord->on('ready', function (Discord $discord) {
    $activity = new Activity($discord, [
        'type' => Activity::TYPE_WATCHING,
        'name' => 'old people gamble'
    ]);
    
    $discord->updatePresence($activity);

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
        global $db;

        if (str_starts_with($message->content, COMMAND_PREFIX)) {
            (string)$actualcmd = substr($message->content, strlen(COMMAND_PREFIX));
            $db->save();
            
            if (str_starts_with($actualcmd, "gamble")) {
                (int)$amt = substr($actualcmd, strlen("gamble "));

                $GLOBALS[$message->author->id]['amt'] = $amt;

                if ($amt == '') {
                    $message->reply("enter the bet");
                } else {
                    if (isset($GLOBALS[$message->author->id]['lastgamble']) && $GLOBALS[$message->author->id]['lastgamble'] + 1 >= time()) {
                        $embed = makeEmbed($discord, "gambling", [], "calm down man, the bot wont run away!", 11867413);
                    
                        $builder = MessageBuilder::new()
                        ->setContent("<@{$message->author->id}>")
                        ->addEmbed($embed);
            
                        $message->reply($builder);
                    } else {
                        gamble($amt, $message);
                        $GLOBALS[$message->author->id]['lastgamble'] = time();
                    }
                }

            } else if (str_starts_with($actualcmd, "balance")) {
                $embed = makeEmbed($discord, "balance", [
                    [
                        'name' => '🍪 Crackers',
                        'value' => $db->getUserBalance($message->author->id),
                        'inline' => true
                    ],
                    [
                        'name' => '🏦 Debt',
                        'value' => $db->getUserDebt($message->author->id),
                        'inline' => true
                    ]
                ], "", 11867413);
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } else if (str_starts_with($actualcmd, "loan")) {
                (int)$amt = substr($actualcmd, strlen("loan "));
                
                $success = $db->takeLoan($message->author->id, $amt);

                $embed = makeEmbed($discord, "bank", [], $success['message'], 11867413);
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } else if (str_starts_with($actualcmd, "pay-debt")) {
                $success = $db->payDebt($message->author->id);

                if ($success) {
                    $embed = makeEmbed($discord, "bank", [], "successfully paid your debt", 11867413);
                } else {
                    $embed = makeEmbed($discord, "bank", [], "you do not have a debt", 11867413);
                }
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } elseif (str_starts_with($actualcmd, "beg")) {
                $beggedcash = random_int(0, 154);

                $embed = makeEmbed($discord, "begging", [], sprintf("someone was nice enough to give you **%d** 🍪", $beggedcash), 11867413);
                
                if ($beggedcash == 0) {
                    $embed = makeEmbed($discord, "begging", [], "you did not get anything.. maybe switch streets?", 11867413);    
                }
                
                if (isset($GLOBALS[$message->author->id]['lastbeg']) && $GLOBALS[$message->author->id]['lastbeg'] + 20 >= time()) {
                    $embed = makeEmbed($discord, "begging", [], sprintf("nobody wants to give you anything.. try again in %d seconds?", ($GLOBALS[$message->author->id]['lastbeg'] + 20) - time()), 11867413);    
                } else {
                    $db->addMoney($message->author->id, $beggedcash);
                    $GLOBALS[$message->author->id]['lastbeg'] = time();
                }
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } elseif (str_starts_with($actualcmd, "work")) {
                $beggedcash = random_int(0, 2054);

                $embed = makeEmbed($discord, "work", [], sprintf("you got **%d** 🍪", $beggedcash), 11867413);
                
                if ($beggedcash == 0) {
                    $embed = makeEmbed($discord, "work", [], "you did not get anything.. maybe wait for a bit?", 11867413);    
                }
                
                if (isset($GLOBALS[$message->author->id]['lastbeat']) && $GLOBALS[$message->author->id]['lastbeat'] + 60 >= time()) {
                    $embed = makeEmbed($discord, "work", [], sprintf("you worked enough for today, come back in %d seconds", ($GLOBALS[$message->author->id]['lastbeat'] + 60) - time()), 11867413);    
                } else {
                    $db->addMoney($message->author->id, $beggedcash);
                    $GLOBALS[$message->author->id]['lastbeat'] = time();
                }
            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } elseif (str_starts_with($actualcmd, "save-db")) {
                if ($message->author->id == 1198738857022201877) {
                    $db->save();

                    $embed = makeEmbed($discord, "database save", [], "successfully saved", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                } else {
                    $embed = makeEmbed($discord, "database save", [], "you do not have access to this command", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                }
            } elseif (str_starts_with($actualcmd, "reload-db")) {
                if ($message->author->id == 1198738857022201877) {
                    $db->reload();

                    $embed = makeEmbed($discord, "database reload", [], "successfully reloaded", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                } else {
                    $embed = makeEmbed($discord, "database save", [], "you do not have access to this command", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                }
            } elseif (str_starts_with($actualcmd, "give")) {
                $mentioned = null;
                
                foreach ($message->mentions as $mention) {
                    if ($mentioned == null) {
                        $mentioned = $mention;
                    }
                }

                if ($mention == null) {
                    $embed = makeEmbed($discord, "give", [], "nobody mentioned in the message", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                } elseif ($mention->bot) {
                    $embed = makeEmbed($discord, "give", [], "cannot give to a bot", 11867413);    
                
                    $builder = MessageBuilder::new()
                    ->setContent("<@{$message->author->id}>")
                    ->addEmbed($embed);
        
                    $message->reply($builder);
                } else {
                    (int)$amt = (int)substr($actualcmd, strlen("give <@{$mention->id}>"));
                    $username = substr($mentioned->username, -strpos($mentioned->username, "#"));

                    $success = $db->give($message->author->id, $mention->id, $amt);

                    if ($success == 0) {
                        $embed = makeEmbed($discord, "give", [
                            [
                                'name' => 'receiver',
                                'value' => $username,
                                'inline' => true
                            ],
                            [
                                'name' => 'amount',
                                'value' => $amt,
                                'inline' => true
                            ],
                            [
                                'name' => 'your balance',
                                'value' => $db->getUserBalance($message->author->id),
                                'inline' => true
                            ],
                            [
                                'name' => sprintf("%s's balance", $username),
                                'value' => $db->getUserBalance($mentioned->id),
                                'inline' => true
                            ]
                        ], "success", 11867413);    
                    
                        $builder = MessageBuilder::new()
                        ->setContent("<@{$mentioned->id}>")
                        ->addEmbed($embed);
            
                        $message->reply($builder);

                        //$givenbyuser = substr($message->author->username, -strpos($message->author->username, "#"));

                        //$embed = makeEmbed($discord, "give", [], sprintf("%s gave you **%d** 🍪!", $givenbyuser, $amt), 11867413);    
                
                        //$builder = MessageBuilder::new()
                        //->setContent("<@{$message->author->id}> <#{$message->channel_id}>")
                        //->addEmbed($embed);
            
                        //$mentioned->sendMessage($builder);
                    } elseif ($success == 1) {
                        $embed = makeEmbed($discord, "give", [], "not enough crackers", 11867413);    
                
                        $builder = MessageBuilder::new()
                        ->setContent("<@{$message->author->id}>")
                        ->addEmbed($embed);
            
                        $message->reply($builder);
                    } elseif ($success == 2) {
                        $embed = makeEmbed($discord, "give", [], "1 or more crackers required", 11867413);    
                
                        $builder = MessageBuilder::new()
                        ->setContent("<@{$message->author->id}>")
                        ->addEmbed($embed);
            
                        $message->reply($builder);
                    }
                }
            } else if (str_starts_with($actualcmd, "leaders")) {
                $content = <<<TEXT
                **BEST GAMBLERS**\n\n
                TEXT;

                $gamblers = $db->getLeaders();
                foreach ($gamblers as $gambler) {
                    $opts = [
                        "http" => [
                            "method" => "GET",
                            "header" => "Content-Type: application/json\r\n" .
                                        "Authorization: Bot {$GLOBALS['token']}\r\n"
                        ]
                    ];
                    
                    $context = stream_context_create($opts);
                    
                    $userjson = json_decode(file_get_contents("https://canary.discord.com/api/v10/users/{$gambler['userId']}", false, $context));
                    $actualcrackers = $gambler['currency'] - $gambler['debt'];

                    if ($gamblers[0] == $gambler) {
                        $content .= "- **{$userjson->global_name} ({$actualcrackers} 🍪) :crown:**\n";
                    } else {
                        $content .= "- {$userjson->global_name} ({$actualcrackers} 🍪)\n";
                    }
                }

                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>\n$content");
    
                $message->reply($builder);
            } else if (str_starts_with($actualcmd, "shop")) {
                (int)$userId = (int)$message->author->id;

                $embed = makeEmbed($discord, "shop", [
                    [
                        'name' => sprintf('gambling upgrade %d (ID:1)', $db->getUserMultiplier($userId) + 1),
                        'value' => ($db->getUserMultiplier($userId) + 1) * 5000 . " 🍪"
                    ]
                ], "to buy, use g.buy ID", 11867413);
                
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            } elseif (str_starts_with($actualcmd, "buy")) {
                (int)$buyid = (int)substr($actualcmd, strlen("buy "));
                (int)$userId = (int)$message->author->id;
                
                switch ($buyid) {
                    case 1:
                        $buyresult = $db->upgradeUserMultiplier($userId);

                        if ($buyresult == 1) {
                            $embed = makeEmbed($discord, "shop", [], "not enough crackers", 11867413);
                            
                            $builder = MessageBuilder::new()
                            ->setContent("<@{$message->author->id}>")
                            ->addEmbed($embed);
                
                            $message->reply($builder);
                        } else {
                            $embed = makeEmbed($discord, "shop", [], sprintf('successfully bought gambling upgrade %d', $db->getUserMultiplier($userId) + 1), 11867413);
                            
                            $builder = MessageBuilder::new()
                            ->setContent("<@{$message->author->id}>")
                            ->addEmbed($embed);
                
                            $message->reply($builder);
                        }
                        break;
                    
                    default:
                        $embed = makeEmbed($discord, "shop", [], "invalid id", 11867413);

                        $builder = MessageBuilder::new()
                        ->setContent("<@{$message->author->id}>")
                        ->addEmbed($embed);
                        
                        $message->reply($builder);
                        break;
                }
            } elseif (str_starts_with($actualcmd, "help")) {
                $embed = makeEmbed($discord, "help", [
                    [
                        'name' => 'g.gamble [amount]',
                        'value' => 'gamble'
                    ],
                    [
                        'name' => 'g.balance',
                        'value' => 'see your balance'
                    ],
                    [
                        'name' => 'g.loan [amount]',
                        'value' => 'take a loan from the bank'
                    ],
                    [
                        'name' => 'g.pay-debt',
                        'value' => 'fully pay the debt'
                    ],
                    [
                        'name' => 'g.beg',
                        'value' => 'beg for crackers'
                    ],
                    [
                        'name' => 'g.work',
                        'value' => 'work (more crackers than begging but bigger cooldown)'
                    ],
                    [
                        'name' => 'g.give <@username> [amount]',
                        'value' => 'give a specific amount of crackers to someone'
                    ],
                    [
                        'name' => 'g.leaders',
                        'value' => 'best gamblers in the world'
                    ],
                    [
                        'name' => 'g.shop',
                        'value' => 'see all shop items'
                    ],
                    [
                        'name' => 'g.buy [id]',
                        'value' => 'buy an item, get the id in the shop'
                    ]
                ], "", 11867413);
                            
                $builder = MessageBuilder::new()
                ->setContent("<@{$message->author->id}>")
                ->addEmbed($embed);
    
                $message->reply($builder);
            }
        }
    });
});

$discord->run();
