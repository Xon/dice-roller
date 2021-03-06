<?php

class Dice_AST
{
    const Addition = '+';
    const Subtraction = '-';
    const Multiplication = '*';
    const Integer_Division = '/';
    
    public static $dice_op = [
        "D"=> ["Name" => "Dice",         "CallBack" => "Roll" , 'expand' => false, 'default_value' => 6 ],       // example; 1d6
        //"H"=> ["Name" => "Take Highest", "CallBack" => "TakeHighest" , 'expand' => 'D', 'default_value' => 10 ], // expands to xd10Hy
        //"L"=> ["Name" => "Take Lowest",  "CallBack" => "TakeLowest" ,  'expand' => 'D', 'default_value' => 10 ],  // expands to xd10Ly
        //"I"=> ["Name" => "Drop Lowest",  "CallBack" => "DropLowest" ,  'expand' => 'D', 'default_value' => 10 ],  // expands to xd10Iy
        "U"=> ["Name" => "Fudge",        "CallBack" => "RollFudge" , 'expand' => false, 'default_value' => 6 ],
/*
Hero System damage rolls: total result is counted as stun damage. On top of that, body damage is calculated by counting ones as zero, 2-5 as 1, and sixes as 2 points of body damage. You can also use the "*" operator for the stun multiplier.
Example: 4B6 / with stun multiplier: 4B6*3
 For "killing"-type damage, use the K operator like this: 4K6*3
�Wild Die (D6 System): The D6 System has a special rule for one of the dice in each roll, it becomes the "Wild Die". The Wild Die is rolled again as long as it comes up with the max result, but if the first roll is a 1 the next roll as well as the highest result of the other dice becomes a penalty. Two ones make a critical failure.
Example: 4W6
You can also roll without the wild die failure option by using the V code:
Example: 4V6
*/
    ];

    var $default_roll_type = "Roll";
    var $default_dice_modifier = "sum";

    public static $dice_modifier_prefix = [
        "SUM"=> ["Name"=> "Return Roll",
             "CallBack" => "SumDice" ,
             "arg" => 1,
            ],
        "COUNT"=> ["Name"=> "Count Higher Than",
             "CallBack" => "CountDice" ,
             "arg" => -1,
            ],
        "REPEAT"=> ["Name"=> "Repeat Dice",
             "CallBack" => "Repeat" ,
             "arg" => 1,
            ],
        "REROLL"=> ["Name"=> "Reroll Dice",
              "CallBack" => "Reroll" ,
              "arg" => -1,
            ],
        "ROUND"=> ["Name"=> "Repeat Dice",
             "CallBack" => "Round" ,
             "arg" => 1,
            ],
        "EXPLODE"=> ["Name"=> "Explode Dice",
              "CallBack" => "Explode" ,
             "arg" => -1,
            ],
    ];

    public static $dice_modifier_suffix = [
        "E"=> ["Name"=> "Count Successes",
             "CallBack" => "DieResultGreaterThan" ,
             "arg" => 1,
             "next_suffix" => "COUNT",
             ],
        "R"=> ["Name"=> "Count Successes with additive re-roll",
             "CallBack" => "AdditiveRerollOnMax" ,
             "arg" => 0,
             "next_prefix" => "E",
             ],
        "F"=> ["Name"=> "Count Successes minus failures",
             "CallBack" => "SubtractN" ,
             "arg" => 0,
             "next_prefix" => "E",
             ],
        "M"=> ["Name"=> "Count Successes \"plus\"",
             "CallBack" => "RerollOnMax" ,
             "arg" => 0,
             "next_prefix" => "E",
             ],
        "S"=> ["Name"=> "Count Successes with everything",
             "CallBack" => "RerollOnMaxSubtractN" ,
             "arg" => 0,
             "next_prefix" => "E",
             ],
        "X"=> ["Name"=> "Count Maximum possible die result counts as two successes",
             "CallBack" => "MaxDieDoubleCount" ,
             "arg" => 0,
             "next_prefix" => "E",
             ],
/*
�Maximum possible die result counts as two successes: to see how many dice rolled equal to or greater than some number, use "X".
Example: 4D6X4
*/
    ];

}