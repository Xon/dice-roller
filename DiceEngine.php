<?php

class AbortDiceRollingException extends Exception
{
}


// Array( 't' => die type, 's' => die size, 'rv' => raw value, 'v' => adjusted value, 'i' => included in final output set );

class DiceEngine
{
    var $rolls = [];
    var $SpecialRolls = [];

    var $RollCount = 0;
    var $DiceSize = 0;
    var $DiceType = 'D';
    var $DiceRollFilter = null;
    var $output_modifier = 0;

    function Roll($RollCount, $DiceSize)
    {
        $this->DiceType = 'D';
        $this->RollCount = $RollCount;
        $this->DiceSize = $DiceSize;
        $this->rolls = $this->DoRolls($this->DiceType, 1, $this->DiceSize, $this->RollCount);
        return $this;
    }

    function RollFudge($RollCount, $DiceSize)
    {
        $this->DiceType = 'U';
        $this->RollCount = $RollCount;
        $this->DiceSize = $DiceSize;
        $this->rolls = $this->DoRolls($this->DiceType, - $this->DiceSize, $this->DiceSize, $this->RollCount);
        return $this;
    }

    function ReRoll($roll, $count = 1)
    {
        switch($roll['t'])
        {
            case 'D':
                $rolls = $this->DoRolls($roll['t'], 1, $roll['s'], $count);
                break;
            case 'U':
                $rolls = $this->DoRolls($roll['t'], - $roll['s'], $roll['s'], $count);
                break;
            default:
                throw new AbortDiceRollingException("Unknown dice type:".$roll['t']);
        }
        // ensure references are handled correctly
        foreach($rolls as &$_roll)
            $this->rolls[] = $_roll;
        return $rolls;
    }

    function DoRolls($DiceType, $min, $max, $count = 1)
    {
        $current_rolls = [];
        while($count > 0)
        {
            $roll = Array( 't' => $DiceType, 's' => $this->DiceSize, 'rv' => mt_rand($min, $max), 'v' => null, 'i' => true );
            $roll['v'] = $roll['rv'];

            $current_rolls[] = $roll;
            $count -= 1;
        }
        return $current_rolls;
    }

    # filtering - basic

    function TakeNHighest($limit)
    {
        $sorted = [];
        foreach($this->rolls as &$roll)
            if ($roll['i'])
                $sorted[] = $roll['v'];
        rsort($sorted, SORT_NUMERIC);
        $lowerbound = 0;
        $count = 0;
        foreach($sorted as $val)
        {
            $lowerbound = $val;
            $count += 1;
            if ($count >= $limit)
                break;
        }
        $count = 0;
        foreach($this->rolls as &$roll)
        {
            if ($count < $limit && $roll['i'] && $roll['v'] >= $lowerbound)
                $count += 1;
            else
                $roll['i'] = false;
        }
        return $this;
    }

    function TakeHighest()
    {
        $highest = ['v' => $this->DiceSize,'t' => $this->DiceSize];
        foreach($this->rolls as &$roll)
        {
            if (!$roll['i'] && $highest['v'] > $roll['v'] )
                $highest = $roll;
            else
                $roll['i'] = false;
        }
        $highest['i'] = true;
        return $this;
    }

    function TakeLowest()
    {
        $lowest = ['v' => $this->DiceSize,'t' => $this->DiceSize];
        foreach($this->rolls as &$roll)
        {
            if (!$roll['i'] && $lowest['v'] > $roll['v'] )
                $lowest = $roll;
            else
                $roll['i'] = false;
        }
        $lowest['i'] = true;
        return $this;
    }

    function DropLowest()
    {
        $lowest = ['v' => $this->DiceSize,'t' => $this->DiceSize];
        foreach($this->rolls as &$roll)
        {
            if (!$roll['i'] && $lowest['v'] > $roll['v'] )
                $lowest = $roll;
        }
        $lowest['i'] = false;
        return $this;
    }

    function Unique()
    {
        $discovered = [];

        foreach($this->rolls as &$roll)
        {
            if (isset($discovered[$roll['v']]) )
                $roll['i'] = false;
            else if (!$roll['i'])
                $discovered[$roll['v']] = true;
        }
        return $this;
    }

    # filtering - 'success' counting

    function DieResultGreaterThan($number)
    {
        $discovered = [];

        foreach($this->rolls as &$roll)
        {
            if ($roll['i'] && $roll['v'] < $number)
                $roll['i'] = false;
        }
        return $this;
    }

    function _RerollOnMax(&$roll)
    {
        // re-roll
        $newroll = $this->ReRoll($roll, 1)[0];
        $newroll['i'] = false;
        // recusively handle re-rolls
        $val = $newroll['v'];
        if ($val == $newroll['s'])
            $val += $this->_RerollOnMax($newroll);
        return $val;
    }

    function AdditiveRerollOnMax()
    {
        $rolls = [];
        foreach($this->rolls as &$roll)
            $rolls[] = &$roll;
        //$rolls = array_merge($this->rolls,[]);
        foreach($rolls as &$roll)
        {
            if ($roll['i'] && $roll['v'] == $roll['s'])
            {
                $val = $this->_RerollOnMax($roll);
                $roll['v'] += $val;
            }
        }
        return $this;
    }

    function RerollOnMax()
    {
        $rolls = [];
        foreach($this->rolls as &$roll)
            $rolls[] = &$roll;
        //$rolls = array_merge($this->rolls,[]);
        foreach($rolls as &$roll)
        {
            if ($roll['i'] && $roll['v'] == $roll['s'])
            {
                $this->_RerollOnMax($roll);
            }
        }
        return $this;
    }

    function SubtractN($n = 1)
    {
        foreach($this->rolls as &$roll)
        {
            if ($roll['i'] && $roll['v'] == $n)
            {
                $this->output_modifier -= 1;
                $roll['i'] = false;
                $this->SpecialRolls[$n][] = $roll;
            }
        }
        return $this;
    }

    function RerollOnMaxSubtractN($n = 1)
    {
        foreach($this->rolls as &$roll)
        {
            if ($roll['i'])
            {
                if ($roll['v'] == $roll['s'])
                {
                    $this->_RerollOnMax($roll);
                }
                else if ($roll['v'] == $n)
                {
                    $this->output_modifier -= 1;
                    $roll['i'] = false;
                    $this->SpecialRolls[$n][] = $roll;
                }
            }
        }
        return $this;
    }

    function MaxDieDoubleCount()
    {
        foreach($this->rolls as &$roll)
        {
            if ($roll['i'])
            {
                if ($roll['v'] == $roll['s'])
                {
                    $this->output_modifier += 1;
                    $this->SpecialRolls[$n][] = $roll;
                }
            }
        }
        return $this;
    }

    # selection = sum/count

    function SumDice()
    {
        $output = $this->output_modifier;
        foreach($this->rolls as $roll)
            if ($roll['i'])
                $output += $roll['v'];
        return $output;
    }

    function CountDice()
    {
        $output = $this->output_modifier;

        foreach($this->rolls as $roll)
            if ($roll['i'])
                $output += 1;

        if ($output < 0)
            $output = 0;

        return $output;
    }

}
