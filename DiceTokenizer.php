<?php


// converts a string into a token stream
class DiceToken
{
    var $TokenId;
    var $TokenName;
    var $Position;
    var $NextPosition;

    public function __construct($TokenName, $TokenId, $Position)
    {
        $this->TokenId = $TokenId;
        $this->TokenName = $TokenName;
        $this->Position = $Position;
    }

    const END                = -2;
    const UNKNOWN            = -1;
    const Number             = 4;
    const Text               = 5;
    const Percentage         = 6; // %

    // maths operations
    const Addition           = 23;
    const Subtraction        = 24;
    const Multiplication     = 25;
    const Integer_Division   = 26;
    const Exponentiation     = 27;


    const Equal              = 28; // =
    const NotEqual           = 29; // !=
    const Smaller            = 30; // <
    const Greater            = 31; // >
    const SmallerOrEqual     = 32; // <=
    const GreaterOrEqual     = 33; // >=

    const Colon              = 37;
    const SemiColon          = 38;

    const OpenParentheses    = 39;  // (
    const CloseParentheses   = 40;  // )
    const Comma              = 41;  // ,
}

class DiceTokenizer
{
    public function __construct($text)
    {
        $this->text = $text;
        $this->textlen = strlen($this->text);

        $this->Token = null;
        $this->Value = 0;
        $this->Position = 0;
        $this->CurrentCharacter = '';

        $this->NextToken = null;
        $this->NextValue = 0;
        $this->NextPosition = 0;
        $this->NextCharacter = @$text[0];

        $this->GetNextCharacter();
        $this->GetNextToken();
    }

    private function GetNextCharacter()
    {
        $this->CurrentCharacter = $this->NextCharacter;
        $this->Position = $this->NextPosition;

        if ($this->NextPosition < $this->textlen )
        {
            $this->NextPosition += 1;
            $this->NextCharacter = @$this->text[$this->NextPosition];

            return $this->NextCharacter;
        }
        else
            return null;
    }

    private function SkipWhiteSpace()
    {
        while ($this->CurrentCharacter != null && ctype_space($this->CurrentCharacter))
            $this->GetNextCharacter();
    }

    private function SkipComment()
    {
    }

    public function NextToken()
    {
        $this->Token = $this->NextToken;
        $this->Value = $this->NextValue;
        $this->Position = $this->NextPosition;

        $this->GetNextToken();

        return $this->Token != null && $this->Token->TokenId > 0;
    }

    private function MakeToken($name)
    {
        return new DiceToken($name,constant('DiceToken::'.$name), $this->Position);
    }

    private function GetNextToken()
    {
        $this->SkipWhiteSpace();
        //$this->SkipComment();
        $token = null;
        $start_position = $this->Position;
        $end_position = $this->Position;
        $character = $this->CurrentCharacter;
        $value = $character;
        $this->GetNextCharacter();
        switch($character)
        {
            case '(':
                $token = $this->MakeToken('OpenParentheses');
                break;
            case ')':
                $token = $this->MakeToken('CloseParentheses');
                break;
            case ',':
                $token = $this->MakeToken('Comma');
                break;
            case '=':
                $token = $this->MakeToken('Equal');
                break;
            case ':':
                $token = $this->MakeToken('Colon');
                break;
            case '+':
                $token = $this->MakeToken('Addition');
                break;
            case '-':
                $token = $this->MakeToken('Subtraction');
                break;
            case '/':
                $token = $this->MakeToken('Integer_Division');
                break;
            case '*':
                $token = $this->MakeToken('Multiplication');
                break;
            case '^':
                $token = $this->MakeToken('Exponentiation');
                break;
            case ';':
                $token = $this->MakeToken('SemiColon');
                break;
            case '%':
                $token = $this->MakeToken('Percentage');
                break;

        }
        if ($token == null && ctype_alpha($character))
        {
            while (ctype_alpha($this->CurrentCharacter))
            {
                $value .= $this->CurrentCharacter;
                $this->GetNextCharacter();
            }
            $token = $this->MakeToken('Text');
        }
        if ($token == null && ctype_digit($character))
        {
            while (ctype_digit($this->CurrentCharacter))
            {
                $value .= $this->CurrentCharacter;
                $this->GetNextCharacter();
            }

            $value = ltrim($value,"0");

            $token = $this->MakeToken('Number');
        }

        if ($character == null)
            $token = $this->MakeToken('END');
        else if ($token == null)
            $token = $this->MakeToken('UNKNOWN');

        $this->NextToken = $token;
        $this->NextValue = $value;
    }
}

