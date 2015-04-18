<?php
include_once('DiceTokenizer.php');
include_once('DiceEngine.php');


class AbortDiceParsingException extends Exception { }


/*

Custom attributes: another way to define custom rules for a single roll command is using attributes. The following attributes are currently supported:
?reroll: ignore one or more results and roll again ( reroll=1,2 4d6 )
?round: round up all results below this number ( round=3 d10 )
?explode: specify one or more results that cause the die to be rolled again and the result added up ( explode=5,6 4d6 )
?count: count the occurrences of specific die results ( count=1,2 10d6 )
You can combine attributes with another, and also use macros (see below) to invoke them. It doesn't matter if an attribute comes before or after the actual dice code, as long as they are separated by a space

*/

/*
EBNF Grammer:
 statement    = expression_modifer | expression ( ";" )
 expression_modifer = repeat | reroll | round | explode | count

 repeat       = ("repeat" | "sum") number expression

 reroll       = "reroll"  number {"," number} expression
 round        = "round"   number {"," number} expression
 explode      = "explode" number {"," number} expression
 count        = "count"   number {"," number} expression

 expression   = (term ("+" | "-") expr) |  term
 term         = (factor ("*" | "/") term) | factor
 factor       = "(" expr ")" | roll | number
 roll         =  [ number ] , ( "d" | "D" ) , number
 number       =  nonzerodigit , { digit }
 digit        =  nonzerodigit | "0"
 nonzerodigit =  "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9"
*/
class DiceParser
{
    var $tokenizer = null;

    var $data;

    public static $dice_op = [
        "D"=> ["Name" => "Dice",         "CallBack" => "Roll" , 'chain' => false],       // example; 1d6
        //"H"=> ["Name" => "Take Highest", "CallBack" => "TakeHighest" , 'chain' => true], // expands to xd10Hy
        //"L"=> ["Name" => "Take Lowest",  "CallBack" => "TakeLowest" , 'chain' => true],  // expands to xd10Ly
        //"I"=> ["Name" => "Drop Lowest",  "CallBack" => "DropLowest" , 'chain' => true],  // expands to xd10Iy
        "U"=> ["Name" => "Fudge",        "CallBack" => "RollFudge" , 'chain' => false ],
/*
Hero System damage rolls: total result is counted as stun damage. On top of that, body damage is calculated by counting ones as zero, 2-5 as 1, and sixes as 2 points of body damage. You can also use the "*" operator for the stun multiplier.
Example: 4B6 / with stun multiplier: 4B6*3
 For "killing"-type damage, use the K operator like this: 4K6*3
•Wild Die (D6 System): The D6 System has a special rule for one of the dice in each roll, it becomes the "Wild Die". The Wild Die is rolled again as long as it comes up with the max result, but if the first roll is a 1 the next roll as well as the highest result of the other dice becomes a penalty. Two ones make a critical failure.
Example: 4W6
You can also roll without the wild die failure option by using the V code:
Example: 4V6
*/
    ];

    var $default_roll_type = "Roll";
    var $default_dice_modifier = "sum";

    public static $dice_modifier_prefix = [
        "sum"=> ["Name"=> "Return Roll",
             "CallBack" => "SumDice" ,
             "arg" => 0,
            ],
        "count"=> ["Name"=> "Count Higher Than",
             "CallBack" => "CountDice" ,
             "arg" => 0,
            ],
        "repeat"=> ["Name"=> "Repeat Dice",
             "CallBack" => "Repeat" ,
             "arg" => 0,
            ],
    ];

    public static $dice_modifier_suffix = [
        "E"=> ["Name"=> "Count Successes",
             "CallBack" => "DieResultGreaterThan" ,
             "arg" => 1,
             "next" => "count",
             ],
        "R"=> ["Name"=> "Count Successes with additive re-roll",
             "CallBack" => "AdditiveRerollOnMax" ,
             "arg" => 0,
             "next" => "E",
             ],
        "F"=> ["Name"=> "Count Successes minus failures",
             "CallBack" => "SubtractN" ,
             "arg" => 0,
             "next" => "E",
             ],
        "M"=> ["Name"=> "Count Successes \"plus\"",
             "CallBack" => "RerollOnMax" ,
             "arg" => 0,
             "next" => "E",
             ],
        "S"=> ["Name"=> "Count Successes with everything",
             "CallBack" => "RerollOnMaxSubtractN" ,
             "arg" => 0,
             "next" => "E",
             ],
        "X"=> ["Name"=> "Count Maximum possible die result counts as two successes",
             "CallBack" => "MaxDieDoubleCount" ,
             "arg" => 0,
             "next" => "E",
             ],
/*
•Maximum possible die result counts as two successes: to see how many dice rolled equal to or greater than some number, use "X".
Example: 4D6X4
*/
    ];


    public function __construct($data)
    {
        $this->data = preg_replace('/\s{2,}/', ' ', strtoupper($data));
    }

    private function ConsumeToken($expected)
    {
        if ($this->tokenizer->Token->TokenId == $expected)
        {
            $value = $this->tokenizer->Value;
            $this->tokenizer->NextToken();
            return $value;
        }
        else
        {
           $parsed = substr($this->data, 0, $this->tokenizer->Position);
           throw new AbortDiceParsingException("Unexpected token: ". $this->tokenizer->Token->TokenName . ", Value: ". $this->tokenizer->Value ." after: ". $parsed);
        }
    }

    public function Parse()
    {
        $tree = array();

        $this->tokenizer = new DiceTokenizer($this->data);
        $this->tokenizer->NextToken();
        while(true)
        {
            while ($this->tokenizer->Token->TokenId == DiceToken::SemiColon)
                $this->ConsumeToken(DiceToken::SemiColon);

            if  ($this->tokenizer->Token->TokenId == DiceToken::END)
                break;

            $tree[] = $this->ParseStatement();

            if ($this->tokenizer->Token->TokenId == DiceToken::SemiColon)
            {
                $this->ConsumeToken(DiceToken::SemiColon);
                continue;
            }
            break;
        }

        if  ($this->tokenizer->Token->TokenId == DiceToken::END)
        {
            return $tree;
        }

        // unknown details!
        $parsed = substr($this->data, 0, $this->tokenizer->Position);
        echo "Unexpected token: ". ($this->tokenizer->Token->TokenId == DiceToken::UNKNOWN ? $this->tokenizer->Token->TokenName : $this->tokenizer->Value) ." after: ". $parsed ."\n" ;
        do
        {
            echo $this->tokenizer->Token->TokenName . "|" .$this->tokenizer->Value. "\n";
        }
        while( $this->tokenizer->NextToken());
        // last token (end or unknown)
        echo $this->tokenizer->Token->TokenName  . "\n";

        return null;
    }

    protected function ParseStatement()
    {
        if ($this->tokenizer->Token->TokenId == DiceToken::Text)
        {
            $dice_modifier_data = $this->tokenizer->Value;
            if (isset(self::$dice_modifier_prefix[$dice_modifier_data]))
            {
                return $this->ParseModifierExpressionStatement();
            }
            else
            {
                throw new AbortDiceParsingException("Unknown dice operation modifier: ". $dice_modifier_data);
            }
        }
        else
        {
            return $this->ParseExpressionStatement();
        }
    }

    protected function makeContext($operation, array $arguments = array())
    {
        return array(
            'op' => $operation,
            'args' => $arguments,
            'children' => array()
        );
    }

    protected function ParseExpressionStatement()
    {
        return $this->ParseExpression();
    }

    protected function ParseModifierExpressionStatement()
    {
        $modifier = $this->ConsumeToken(DiceToken::Text);
        $number = $this->ConsumeToken(DiceToken::Number);

        $context = $this->makeContext($modifier, array($number));
        $context['children'][] = $this->ParseExpression();
        return $context;
    }

    protected function mergeChildren($op, array &$context, $child)
    {
        if (isset($child['op']) && $child['op'] == $op)
        {
            $context['children'] = array_merge($context['children'], $child['children']);
        }
        else
        {
            $context['children'][] = $child;
        }
    }

    protected function ParseExpression()
    {
        $context = $this->ParseTerm();

        if ($this->tokenizer->Token->TokenId == DiceToken::Addition)
        {
            $this->ConsumeToken(DiceToken::Addition);

            $new_sibling = $context;
            $context = $this->makeContext('+');
            $context['children'][] = $new_sibling;
            $this->mergeChildren('+', $context, $this->ParseExpression());
        }
        else if ($this->tokenizer->Token->TokenId == DiceToken::Subtraction)
        {
            $this->ConsumeToken(DiceToken::Subtraction);

            $new_sibling = $context;
            $context = $this->makeContext('-');
            $context['children'][] = $new_sibling;
            $this->mergeChildren('-', $context, $this->ParseExpression());
        }
        return $context;
    }

    protected function ParseTerm()
    {
        $context = $this->ParseFactor();

        if ($this->tokenizer->Token->TokenId == DiceToken::Multiplication)
        {
            $this->ConsumeToken(DiceToken::Multiplication);
            $new_sibling = $context;
            $context = $this->makeContext('*');
            $context['children'][] = $new_sibling;
            $this->mergeChildren('*',$context, $this->ParseTerm());
        }
        else if ($this->tokenizer->Token->TokenId == DiceToken::Integer_Division)
        {
            $this->ConsumeToken(DiceToken::Integer_Division);
            $new_sibling = $context;
            $context = $this->makeContext('/');
            $context['children'][] = $new_sibling;
            $this->mergeChildren('/',$context, $this->ParseTerm());
        }
        return $context;
    }

    protected function ParseFactor()
    {
        if ($this->tokenizer->Token->TokenId == DiceToken::OpenParentheses)
        {
            $this->ConsumeToken(DiceToken::OpenParentheses);
            $context = $this->ParseExpression();
            $this->ConsumeToken(DiceToken::CloseParentheses);
            return $context;
        }
        else if ($this->tokenizer->Token->TokenId == DiceToken::Number &&
                 $this->tokenizer->NextToken->TokenId == DiceToken::Text)
            return $this->ParseRoll();
        else
            return $this->ParseNumber();
    }

    protected function ParseNumber()
    {
        return $this->ConsumeToken(DiceToken::Number);
    }

    protected function ParseRoll()
    {
        $dice_count = 1;
        if ($this->tokenizer->Token->TokenId == DiceToken::Number)
        {
            $dice_count = $this->ConsumeToken(DiceToken::Number);
        }

        $dice_op_data = $this->ConsumeToken(DiceToken::Text);
        $dice_size = $this->ConsumeToken(DiceToken::Number);

        if (isset(self::$dice_op[$dice_op_data]))
        {
            $dice_op = self::$dice_op[$dice_op_data];
            if (!$dice_op["chain"])
                $root_call = $dice_op["CallBack"];
            else
                $root_call = $this->default_roll_type;

            //$context = $this->makeContext($root_call);


            $context = $this->makeContext($dice_op_data, array($dice_count, $dice_size));
/*

            if ($dice_op["chain"] && isset($dice_op["CallBack"]) && $dice_op["CallBack"])
                $call .= $dice_op["CallBack"].'()->';

            if ($this->tokenizer->Token->TokenId == DiceToken::Text)
            {
                $dice_modifier_data = $this->ConsumeToken(DiceToken::Text);
                if (isset(self::$dice_modifier_suffix[$dice_modifier_data]))
                    $dice_modifier = self::$dice_modifier_suffix[$dice_modifier_data];
                else
                    throw new AbortDiceParsingException("Unknown dice operation modifier: ". $dice_modifier_data);
            }
            else
                $dice_modifier = self::$dice_modifier_suffix[$this->default_dice_modifier];
            while (true)
            {
                $args = [];
                for($i = 0; $i < $dice_modifier["arg"]; $i++ )
                {
                    $args[] = $this->ConsumeToken(DiceToken::Number);
                }
                if (isset($dice_modifier["CallBack"]) && $dice_modifier["CallBack"])
                    $call .= $dice_modifier["CallBack"].'('.join(",",$args).')';
                if (isset($dice_modifier["next"]))
                {
                    $next = $dice_modifier["next"];
                    $call .= '->';
                    if (!isset($this->dice_modifier[$next]))
                        throw new AbortDiceParsingException("Unknown dice operation modifier: ". $next);

                    $dice_modifier = $this->dice_modifier[$next];
                    continue;
                }
                break;
            }
*/
            return $context;
        }
        else
            throw new AbortDiceParsingException("Unknown dice operation: ". $dice_op_data);
    }
}