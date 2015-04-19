<?php
include_once('DiceTokenizer.php');
include_once('DiceEngine.php');
include_once('Dice_AST.php');


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
 statement          = [ expression_modifer ] expression ( ";" )
 expression_modifer = sum | repeat | count | reroll | round | explode

 sum                = "sum" "=" number
 repeat             = "repeat" "=" number
 count              = "count" "=" number {"," number}
 reroll             = "reroll" "=" number {"," number}
 round              = "round" "=" number
 explode            = "explode" "=" number {"," number}

 expression         = (term ("+" | "-") expression) |  term
 term               = (factor ("*" | "/") term) | factor
 factor             = "(" expression ")" | roll | number
 
 roll               =  [ number ] ( "d" | "D" ) number
 
 number             =  nonzerodigit , { digit }
 digit              =  nonzerodigit | "0"
 nonzerodigit       =  "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9"
*/
class DiceParser
{
    var $tokenizer = null;

    var $data;

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
        $parsed = substr($this->data, 0, $this->tokenizer->Token->Position - 1);
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
            return $this->ParseModifierExpression();
        }
        else
        {
            return $this->ParseExpression();
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

    protected function ParseModifierExpression()
    {
        $dice_modifier = strtoupper( $this->ConsumeToken(DiceToken::Text) );
        if (!isset(Dice_AST::$dice_modifier_prefix[$dice_modifier]))
        {
            throw new AbortDiceParsingException("Unknown dice operation modifier: ". $dice_modifier);
        }

        $modifier = Dice_AST::$dice_modifier_prefix[$dice_modifier];
        $args = array();
        if ($modifier['arg'] != 0 )
        {
            $this->ConsumeToken(DiceToken::Equal);
            while (true)
            {
                $args[] = $this->ConsumeToken(DiceToken::Number);
                if ($this->tokenizer->Token->TokenId != DiceToken::Comma)
                {
                    break;
                }
                $this->ConsumeToken(DiceToken::Comma);
            }
        }

        if($modifier['arg'] > 0 && count($args) != $modifier['arg'])
        {
            throw new AbortDiceParsingException("Expected ".$modifier['arg']." arguments but got ".count($args)." for the operation modifier: ". $dice_modifier);
        }
        else if($modifier['arg'] == -1 && count($args) == 0)
        {
            throw new AbortDiceParsingException("Expected at least 1 arguments but got 0 for the operation modifier: ". $dice_modifier);
        }

        $context = $this->makeContext($dice_modifier, $args);
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
            $context = $this->makeContext(Dice_AST::Addition);
            $context['children'][] = $new_sibling;
            $this->mergeChildren(Dice_AST::Addition, $context, $this->ParseExpression());
        }
        else if ($this->tokenizer->Token->TokenId == DiceToken::Subtraction)
        {
            $this->ConsumeToken(DiceToken::Subtraction);

            $new_sibling = $context;
            $context = $this->makeContext(Dice_AST::Subtraction);
            $context['children'][] = $new_sibling;
            $this->mergeChildren(Dice_AST::Subtraction, $context, $this->ParseExpression());
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
            $context = $this->makeContext(Dice_AST::Multiplication);
            $context['children'][] = $new_sibling;
            $this->mergeChildren(Dice_AST::Multiplication,$context, $this->ParseTerm());
        }
        else if ($this->tokenizer->Token->TokenId == DiceToken::Integer_Division)
        {
            $this->ConsumeToken(DiceToken::Integer_Division);
            $new_sibling = $context;
            $context = $this->makeContext(Dice_AST::Integer_Division);
            $context['children'][] = $new_sibling;
            $this->mergeChildren(Dice_AST::Integer_Division,$context, $this->ParseTerm());
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

        $dice_op_data = strtoupper($this->ConsumeToken(DiceToken::Text));

        if (isset(Dice_AST::$dice_op[$dice_op_data]))
        {
            $dice_op = Dice_AST::$dice_op[$dice_op_data];

            if ($this->tokenizer->Token->TokenId == DiceToken::Number)
            {
                $dice_size = $this->ConsumeToken(DiceToken::Number);
            }
            else
            {
                $dice_size = $dice_op['default_value'];
            }

            if ($dice_op['expand'])
            {
                if (empty(Dice_AST::$dice_op[$dice_op['expand']]))
                {
                    throw new AbortDiceParsingException("Invalid internal configuration for: ". $dice_op_data);
                }
                $dice_modifier_data = $dice_size;
                $dice_size = $dice_op['default_value'];
            }

            $context = $this->makeContext($dice_op_data, array($dice_count, $dice_size));
            
            /*
            //$context = $this->makeContext($root_call);



            if ($this->tokenizer->Token->TokenId == DiceToken::Text)
            {
                $dice_modifier_data = $this->ConsumeToken(DiceToken::Text);
                if (isset(Dice_AST::$dice_modifier_suffix[$dice_modifier_data]))
                    $dice_modifier = Dice_AST::$dice_modifier_suffix[$dice_modifier_data];
                else
                    throw new AbortDiceParsingException("Unknown dice operation modifier: ". $dice_modifier_data);
            }
            else
                $dice_modifier = Dice_AST::$dice_modifier_suffix[$this->default_dice_modifier];

            if ($dice_op["chain"] && isset($dice_op["CallBack"]) && $dice_op["CallBack"])
                $call .= $dice_op["CallBack"].'()->';

            if ($this->tokenizer->Token->TokenId == DiceToken::Text)
            {
                $dice_modifier_data = $this->ConsumeToken(DiceToken::Text);
                if (isset(Dice_AST::$dice_modifier_suffix[$dice_modifier_data]))
                    $dice_modifier = Dice_AST::$dice_modifier_suffix[$dice_modifier_data];
                else
                    throw new AbortDiceParsingException("Unknown dice operation modifier: ". $dice_modifier_data);
            }
            else
                $dice_modifier = Dice_AST::$dice_modifier_suffix[$this->default_dice_modifier];
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