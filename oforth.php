<?php

/**
 * $ php vendor/bin/phpstan analyze --level 8 oforth.php
 */

//require_once(__DIR__ . "/vendor/autoload.php");

error_reporting(E_ALL);
ini_set('display_errors', 1);

class ReverseArrayIterator implements Iterator {
    private $array;
    private $position;

    public function __construct(array $array) {
        $this->array = $array;
        $this->position = count($array);
    }

    public function rewind(): void {
        $this->position = count($this->array);
    }

    public function current(): mixed {
        return $this->array[$this->position - 1];
    }

    public function key(): mixed {
        return $this->position - 1;
    }

    public function next(): void {
        $this->position--;
    }

    public function valid(): bool {
        return $this->position > 0;
    }
}

class Stack_ implements IteratorAggregate, Countable {
    private $items = array();

    public function push($item) {
        array_push($this->items, $item);
    }

    public function pop() {
        if ($this->isEmpty()) {
            //throw new RuntimeException("Cannot pop from an empty stack.");
            return null;
        }
        return array_pop($this->items);
    }

    public function shift() {
        if ($this->isEmpty()) {
            return null;
        }
        return array_shift($this->items);
    }

    public function peek() {
        if ($this->isEmpty()) {
            throw new RuntimeException("Cannot peek an empty stack.");
        }
        return end($this->items);
    }

    public function isEmpty() {
        return empty($this->items);
    }

    public function count(): int {
        return count($this->items);
    }

    public function clear() {
        $this->items = array();
    }

    public function contains($item) {
        return in_array($item, $this->items, true);
    }

    public function toArray() {
        return $this->items;
    }

    public function getIterator(): Traversable {
        return new ReverseArrayIterator($this->items);
    }

    public function getFifoIterator() {
        return new ArrayIterator($this->items);
    }

    public function iterateFifo(callable $callback) {
        foreach ($this->items as $item) {
            $callback($item);
        }
    }

    public function toLifoArray() {
        return array_reverse($this->items);
    }

    public function toFifoArray() {
        return $this->items;
    }
}

// Example usage
/*
$stack = new Stack_();
$stack->push('A');
$stack->push('B');
$stack->push('C');

// Iterate in LIFO order (default)
echo "LIFO order:\n";
foreach ($stack as $item) {
    echo $item . "\n"; // Outputs: C, B, A
}

// Iterate in FIFO order
echo "\nFIFO order:\n";
foreach ($stack->getFifoIterator() as $item) {
    echo $item . "\n"; // Outputs: A, B, C
}

// Pop items
echo "\nPopping items:\n";
while (!$stack->isEmpty()) {
    echo $stack->pop() . "\n"; // Outputs: C, B, A
}
*/

class StringBuffer
{
    /** @var string */
    private $buffer;

    /** @var int */
    private $pos = 0;

    private $inside_quote = 0;

    public function __construct(string $s)
    {
        // Remove comments
        $s = preg_replace('/\\\.*$/m', '', $s);
        // Normalize string
        $s = trim((string) preg_replace('/[\t\n\r\s]+/', ' ', $s));
        // Two extra spaces for the while-loop to work
        $this->buffer = $s. '  ';
    }

    public function next()
    {
        // No next word?
        if (!isset($this->buffer[$this->pos])) {
            return null;
        }

        if ($this->buffer[$this->pos] === '"') {
            $this->inside_quote = 1 - $this->inside_quote;
        }
        if ($this->inside_quote === 1) {
            $nextQuote = strpos($this->buffer, '"', $this->pos + 1);
            $result = substr($this->buffer, $this->pos, $nextQuote - $this->pos + 1);
            $this->pos = $nextQuote + 2;
            $this->inside_quote = 0;
        } else {
            $nextSpace = strpos($this->buffer, ' ', $this->pos);
            $result = substr($this->buffer, $this->pos, $nextSpace - $this->pos);
            $this->pos = $nextSpace + 1;
        }
        return $result;
    }
}

abstract class Object_
{
    abstract public function toString(): string;

    public function receive(Stack_ $stack, Message $m)
    {
        switch ($m->messageName) {
            case "swap":
                $a = $stack->pop();
                $stack->push($this);
                $stack->push($a);
                return;
            case "drop":
                // Do nothing, receiver is already removed from stack.
                break;
            default:
                $stack->push($this);
                printf('Message not supported: ' . $m->messageName . PHP_EOL);
                break;
        }
    }
}

class Sym extends Object_
{
    public $val;
    public function __construct(string $val)
    {
        $this->val = $val;
    }
    public function toString(): string
    {
        return sprintf('Sym(%s)', $this->val);
    }
    public function receive(Stack_ $stack, Message $m)
    {
        parent::receive($stack, $m);
    }
}

class Num extends Object_
{
    public $num;

    public function __construct($val)
    {
        $this->num = (float) $val;
    }

    public function toString(): string
    {
        return sprintf('Num(%.2f)', $this->num);
    }

    public function receive(Stack_ $stack, Message $m)
    {
        switch ($m->messageName) {
            case '+':
                $a = $stack->pop();
                if ($a instanceof Num) {
                    $res = $a->num + $this->num;
                    $stack->push(new Num($res));
                    return;
                } else {
                    throw new Exception('Cannot add to non-num');
                }
                break;
            default:
                parent::receive($stack, $m);
                break;
        }
    }
}

class Word extends Object_
{
    public string $w;

    public function __construct($w)
    {
        $this->w = $w;
    }

    public function toString(): string
    {
        return sprintf('Word(%s)', $this->w);
    }

    public function exec(Stack_ $stack, Stack_ $symstream): void
    {
    }
}

class String_ extends Object_
{
    public string $val;

    public function __construct(string $val)
    {
        $this->val = $val;
    }

    public function toString(): string
    {
        return sprintf('String(%s)', $this->val);
    }

    public function receive(Stack_ $stack, Message $m)
    {
        switch ($m->messageName) {
            case "length":
                $stack->push(new Num(strlen($this->val)));
                return;
            case "echo":
                echo $this->val;
                return;
            default:
                parent::receive($stack, $m);
        }
    }
}

class Message extends Object_
{
    public $messageName;

    public function __construct(string $s)
    {
        $this->messageName = $s;
    }

    public function toString(): string
    {
        return sprintf('Message(%s)', $this->messageName);
    }

    public function receive(Stack_ $stack, Message $m)
    {
        parent::receive($stack, $m);
    }
}

class Function_ extends Object_
{
    public String_ $name;

    public $refFunction;

    public function __construct(String_ $val)
    {
        if (!function_exists($val->val)) {
            throw new Exception('Function does not exist: ' . $val->val);
        }
        $this->name = $val;
        $this->refFunction = new ReflectionFunction($val->val);
    }

    public function toString(): string
    {
        return sprintf("Function(%s)", $this->name->toString());
    }
}

class Class_ extends Object_
{
    public $val;
    public function __construct($val)
    {
        $this->val = $val;
    }

    public function toString(): string
    {
        return sprintf("Class(%s)", $this->val);
    }

    public function receive(Stack_ $stack, Message $m)
    {
        switch ($m->messageName) {
            case "new":
                $n = $this->val;
                // TODO hack for _
                if ($n == "File") {
                    $n = "File_";
                } elseif ($n == "Function") {
                    $n = "Function_";
                }
                $arg = $stack->pop();
                if (class_exists($n)) {
                    $c = new $n($arg);
                    $stack->push($c);
                } else {
                    throw new Exception('Class does not exist: ' . $this->val);
                }
                return;
        }
        parent::receive($stack, $m);
    }
}


// todo move to dict
function isClass(string $word)
{
    $classes = [
        'File',
        'Function',
    ];
    return in_array($word, $classes);
}

/**
 * Main parse loop
 */
function parse_buffer(StringBuffer $buffer, Dicts $dicts, Stack_ $stack): Stack_
{
    $dict = $dicts->getCurrentDict();
    while ($word = $buffer->next()) {
        $o = null;
        $firstChar = $word[0];
        if (ctype_digit($word)) {
            $o = new Num($word);
        } elseif ($dict->isString($word)) {
            $o = new String_(trim($word, '"'));
        } elseif ($word[0] === "'") {
            $o = new Sym(trim($word, "'"));
        } elseif ($dict->isSmalltalkMessage($word)) {
            $o = new Message(trim($word, ':'), $dicts);
        } elseif (isClass($word)) {
            $o = new Class_($word);
        } else {
            $o = new Word($word);
        }
        /*
        $fn = $dicts->getWord($word);
        if (trim($word, '"') !== $word) {
            $stack->push($word);
        } elseif (ctype_digit($word)) {
            // Digit
            $stack->push($word);
        } elseif ($fn) {
            // Execute dict word
            $fn($stack, $buffer, $word);
        } else {
            $stack->push($word);
            //throw new RuntimeException('Word is not a string, not a number, and not in dictionary: ' . $word);
        }
        */
        if ($o) {
            $stack->push($o);
        } else {
            throw new Exception('Could not parse word as any object: ' . $word);
        }
    }
    return $stack;
}

/**
 * Main eval loop
 */
function eval_symbolstream(Stack_ $symbolstream)
{
    $stack = new Stack_();
    $m = null;
    while ($o = $symbolstream->shift()) {
        if ($o instanceof Message) {
            // Get rid of prev on stack
            $prev = $stack->pop();
            $prev->receive($stack, $o);
        } else if ($o instanceof Word) {
            $o->exec($stack, $symbolstream);
        } else {
            $stack->push($o);
        }
    }
    return $stack;
}

/**
 * Parsing the string buffer populates the environment, which is returned.
 *
 * @return string
 */
function getSelectFromColumns(Stack_ $cols)
{
    $sql = '';
    foreach ($cols as $col) {
        $sql .= trim($col['select'], '"') . ', ';
    }
    return trim(trim($sql), ',');
}

function isGzipped($data) {
	// Check for the GZIP magic number (first two bytes)
	// GZIP magic number: 0x1f 0x8b
	if (strlen($data) < 2) {
		return false; // Too short to be gzipped
	}
	return substr($data, 0, 2) === "\x1f\x8b";
}

class Dict extends ArrayObject
{
    public $words = [];
    public function addWord(Word $word)
    {
        $this->words[$word->w] = $word;
    }

    public function removeWord(Word $word)
    {
        unset($this[$word->w]);
    }

    // Smalltalk messages end with ':'
    public function isSmalltalkMessage(string $word)
    {
        return $word[strlen($word) -1] === ':';
    }

    public function isString(string $word)
    {
        return $word[0] === '"' && $word[strlen($word) -1] === '"';
    }
}

class Dicts
{
    public $dicts = [];
    public $currentDict = 'root';

    public function addDict(string $name, Dict $d)
    {
        $this->dicts[$name] = $d;
    }

    public function setCurrent(string $name)
    {
        $this->currentDict = $name;
    }

    public function getCurrentDict()
    {
        return $this->dicts[$this->currentDict];
    }

    /**
     * @return ?string
     */
    public function getWord(string $word)
    {
        $dict = $this->getCurrentDict();
        if (isset($dict[$word])) {
            return $dict[$word];
        }
        $dict = $this->dicts['root'];
        if (isset($dict[$word])) {
            return $dict[$word];
        }
        return null;
    }
}

class Array_ extends ArrayObject
{
    public $name;
}

// OBS New dicts
$dicts = new Dicts();
$rootDict = new Dict();

// Dot operator
//$rootDict->addWord('.', function ($stack, $buffer, $word) {
    //$a = $stack->pop();
    //echo $a;
//});

//$rootDict->addWord('if', function(Stack_ $stack, StringBuffer $buffer, string $word) {
$rootDict->addWord(new Word('if'));
    //error_log('if word executed');
    // execute until `then`
    // check if stack is true
    // if true, execute from `then` to end of line
    // TODO block?

// todo <- ?
//$rootDict->addWord('swap', function(Stack_ $stack, StringBuffer $buffer, string $word) use ($rootDict, $db) {
    // nothing
//});

// todo use new:
//$rootDict->addWord('new', function(Stack_ $stack, StringBuffer $buffer, string $word) use ($rootDict, $db) {
    // nothing
//});

$dicts->addDict('root', $rootDict);

$stack = new Stack_();
while (true) {
    echo "> ";
    $line = trim((string) fgets(STDIN)); // Read a line from stdin

    if (strtolower($line) === 'exit') {
        echo "Exiting loop\n";
        break; // Exit the loop if the user types 'exit'
    }

    if (!empty($line)) {
        try {
            $symbolstream = parse_buffer(new StringBuffer($line), $dicts, $stack);
            $stack = eval_symbolstream($symbolstream);
            if ($stack->count() > 0) {
                foreach ($stack->getFifoIterator() as $o) {
                    echo $o->toString();
                    echo ' ';
                }
            }
        } catch (Throwable $e) {
            //echo "Exception thrown: " . $e->getMessage();
            $output = get_class($e) . ": " . $e->getMessage() . "\n";

            // Add the line where the exception was thrown
            $exceptionFile = $e->getFile() ?: 'unknown';
            $exceptionLine = $e->getLine() ?: '';
            $output .= "\tat " . basename($exceptionFile) . ':' . $exceptionLine . "\n";

            $trace = $e->getTrace();
            foreach ($trace as $frame) {
                $class = isset($frame['class']) ? $frame['class'] : '';
                $function = $frame['function'];
                $file = isset($frame['file']) ? $frame['file'] : 'unknown';
                $line = isset($frame['line']) ? $frame['line'] : '';

                $location = basename($file) . ':' . $line;

                if ($class && $function) {
                    // It's a method call
                    $type = isset($frame['type']) ? $frame['type'] : '->';
                    $output .= "\tat " . $class . $type . $function . '(' . $location . ")\n";
                } elseif ($function) {
                    // It's a function call
                    $output .= "\tat " . $function . '(' . $location . ")\n";
                } else {
                    // Maybe it's a closure or something else
                    $output .= "\tat " . $location . "\n";
                }
            }
            echo $output;
        }
    } else {
        sleep(1);
    }
    echo PHP_EOL;
}
