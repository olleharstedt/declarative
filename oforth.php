<?php

/**
 */

require_once(__DIR__ . "/vendor/autoload.php");

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Latitude\QueryBuilder\Engine\MySqlEngine;
use Latitude\QueryBuilder\QueryFactory;
use function Latitude\QueryBuilder\field;
use function Latitude\QueryBuilder\group;
use function Latitude\QueryBuilder\alias;
use function Latitude\QueryBuilder\func;

$queryBuilder = new QueryFactory(new MySqlEngine());

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
                    error_log("adding {$a->num} and {$this->num}");
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

class String_ extends Object_
{
    public $val;

    public function __construct($val)
    {
        $this->val = $val;
    }

    public function toString(): string
    {
        return sprintf('String(%s)', $this->val);
    }

    public function receive(Stack_ $stack, Message $m)
    {
        parent::receive($stack, $m);
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

class Attribute_ extends Object_
{
    public function toString(): string
    {
        throw new Exception('Not implemented');
    }
    public function receive(Stack_ $stack, Message $m)
    {
        parent::receive($stack, $m);
    }
}

class Entity extends Object_
{
    /** @var int */
    public $id;

    /** @var string 255 */
    public $name;

    /** @var string */
    public $description;

    /** @var DateTime */
    public $created;

    /** @var boolean */
    public $archived;

    /** @var string[] List of all names this entity is */
    public $isA = [];

    /** @var string[] List of all names this entity has */
    public $hasA = [];

    /** @var Attribute_[] */
    public $attributes = [];

    /** @param array<mixed> $data */
    public static function make(array $data): self
    {
        $self = new self();
        $self->id = (int) $data['id'];
        $self->name = $data['name'];
        $self->description = $data['description'];
        $self->created = new DateTime($data['created']);
        $self->archived = $data['archived'] ? true : false;
        return $self;
    }

    public static function findByName(PDO $db, string $name): ?self
    {
        $sql = "SELECT * FROM entity WHERE name = :name";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        $entity = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch as an associative array
        if ($entity) {
            $self = self::make($entity);
            // Fetch all is_a
            $subject_id = (int) $entity['id'];
            $sql = <<<SQL
SELECT * FROM entity
JOIN is_a ON entity.id = is_a.object_id AND is_a.subject_id = $subject_id
SQL;
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $is = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all as associative arrays
            foreach ($is as $i) {
                $self->isA[] = $i['name'];
            }

            // Fetch attributes
            $queryBuilder = new QueryFactory(new MySqlEngine());
            $query = $queryBuilder
                ->select('*')
                ->from('attribute')
                ->where(field('entity_id')->eq($entity['id']))
                ->compile();
            $stmt = $db->prepare($query->sql());
            $stmt->execute($query->params());
            $attrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($attrs as $att) {
                //$entity['attribute'] = $attrs ?? [];
                $self->attributes = Attribute_::make($att);
            }
            return $self;
        }
        return null;
    }

    public function toString(): string
    {
        throw new Exception('Not implemented');
    }
    public function receive(Stack_ $stack, Message $m)
    {
        parent::receive($stack, $m);
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

class File_ extends Object_
{
    public String_ $filename;

    public function __construct(String_ $val)
    {
        $this->filename = $val;
    }

    public function toString(): string
    {
        return sprintf("File(%s)", $this->filename->toString());
    }

    public function receive(Stack_ $stack, Message $m)
    {
        switch ($m->messageName) {
            case 'req':
                printf("require_once(%s)\n", $this->filename->toString());
                require_once($this->filename->val);
                $stack->push($this);
                return;
        }
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
        if (ctype_digit($word)) {
            $o = new Num($word);
        } elseif ($dict->isString($word)) {
            $o = new String_(trim($word, '"'));
        } elseif ($dict->isSmalltalkMessage($word)) {
            $o = new Message(trim($word, ':'), $dicts);
        } elseif ($dict->isMessage($word)) {
            $o = new Message($word, $dicts);
        } elseif (isClass($word)) {
            $o = new Class_($word);
        } else {
            $o = new Sym($word);
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
    public function addWord(string $word, callable $fn)
    {
        $this[$word] = $fn;
    }

    public function removeWord(string $word)
    {
        unset($this[$word]);
    }

    // Smalltalk messages end with ':'
    public function isSmalltalkMessage($word)
    {
        return $word[strlen($word) -1] === ':';
    }

    public function isMessage($word)
    {
        return isset($this[$word]) && $this[$word] != null;
    }

    public function isString($word)
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

// Memory to save variables etc in
$mem = new ArrayObject();
$dicts = new Dicts();

// SQL dictionary
$sqlDict = new Dict();
$sqlDict->addWord('/', function($stack, $buffer, $word) {
    $b = $stack->pop();
    $a = $stack->pop();
    $stack->push('(' . $a . ' / ' . $b . ')');
});
$sqlDict->addWord('-', function($stack, $buffer, $word) {
    $b = $stack->pop();
    $a = $stack->pop();
    $stack->push('(' . $a . ' - ' . $b . ')');
});
$sqlDict->addWord('*', function($stack, $buffer, $word) {
    $b = $stack->pop();
    $a = $stack->pop();
    $stack->push('(' . $a . ' * ' . $b . ')');
});
$sqlDict->addWord('round', function($stack, $buffer, $word) {
    $b = $stack->pop();
    $a = $stack->pop();
    $stack->push('round(' . $a . ', ' . $b . ')');
});
$dicts->addDict('sql', $sqlDict);

// PHP dictionary
$phpDict = new Dict();
$phpDict->addWord('count', function ($stack, $buffer, $word) use ($mem) {
    $varName = $buffer->next();
    $var = $mem[$varName];
    $stack->push(count($var));
});
// TODO: Hard-coded variable?
$phpDict->addWord('rows', function ($stack, $buffer, $word) use ($mem) {
    $stack->push($word);
});
$phpDict->addWord('sum', function ($stack, $buffer, $word) use ($mem) {
    $fieldName = $buffer->next();
    $data = $stack->pop();
    $sum = 0;
    foreach ($data as $row) {
        $sum += $row[$fieldName];
    }
    $stack->push($sum);
});
$phpDict->addWord('/', function($stack, $buffer, $word) {
    $a = (float) $stack->pop();
    $b = (float) $stack->pop();
    $stack->push($b / $a);
});
$dicts->addDict('php', $phpDict);

// Root words, available from all dictionaries
$rootDict = new Dict();
$rootDict->addWord('only', function ($stack, $buffer, $word) {
    global $dicts;
    $dictname = $buffer->next();
    $dicts->setCurrent($dictname);
});
$rootDict->addWord('@', function($stack, $buffer, $word) use ($mem) {
    $varname = $stack->pop();
    $stack->push($mem[$varname]);
});
$rootDict->addWord('.', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    echo $a;
});
$rootDict->addWord('drop', function ($stack, $buffer, $word) {
    $stack->pop();
});
$rootDict->addWord('swap', function ($stack, $buffer, $word) use ($sqlDict) {
    $a = $stack->pop();
    $b = $stack->pop();
    $stack->push($a);
    $stack->push($b);
});
$dicts->addDict('root', $rootDict);

// Main, default dictionary
$mainDict = new Dict();
$mainDict->addWord('swap', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    $b = $stack->pop();
    $stack->push($a);
    $stack->push($b);
});
$mainDict->addWord('dup', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    $stack->push(clone $a);
    $stack->push(clone $a);
});

$mainDict->addWord('+', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    $b = $stack->pop();
    $stack->push($a + $b);
});

$mainDict->addWord('var', function($stack, $buffer, $word) use ($mem, $mainDict) {
    $varName = $buffer->next();
    $mainDict->addWord($varName, function($stack, $buffer, $word) use ($mem) {
        $stack->push($word);
    });
});

// Remove variable from memory
$mainDict->addWord('unset', function($stack, $buffer, $word) use ($mem, $mainDict) {
    $varName = $buffer->next();
    unset($mem[$varName]);
});

$mainDict->addWord('const', function($stack, $buffer, $word) use ($mainDict) {
    $value = $stack->pop();
    $name = $buffer->next();
    $mainDict->addWord($name, function($stack, $buffer, $word) use ($value) {
        $stack->push($value);
    });
});
$mainDict->addWord('new', function($stack, $buffer, $word) {
    $type = $buffer->next();
    switch ($type) {
        case 'table':
            // Fallthru
        case 'array':
            $stack->push(new ArrayObject());
            break;
        case 'list':
            // Fallthru
        case 'stack':
            $stack->push(new Stack_());
            break;
        default:
            throw new RuntimeException('Unknown type for new: ' . $type);
    }
});
$mainDict->addWord('push', function($stack, $buffer, $word) use ($mainDict) {
    $value = $stack->pop();
    $s = $stack->pop();
    $s->push($value);
});
$mainDict->addWord('pop', function($stack, $buffer, $word) use ($mainDict) {
    $s   = $stack->pop();
    $stack->push($s->pop());
});
$mainDict->addWord('set', function($stack, $buffer, $word) use ($mainDict) {
    $value = $stack->pop();
    $table = $stack->pop();
    $key   = $buffer->next();
    $table[$key] = $value;
});
$rootDict->addWord(':', function ($stack, $buffer, $word) use ($rootDict) {
    $wordsToRun = [];
    while (($w = $buffer->next()) !== ';') {
        $wordsToRun[] = $w;
    }

    $name = $wordsToRun[0];
    unset($wordsToRun[0]);

    $rootDict->addWord($name, function ($stack, $buffer, $_word) use ($wordsToRun) {
        global $dicts;
        $b = new StringBuffer(implode(' ', $wordsToRun));
        while ($word = $b->next()) {
            // TODO: Add support for string
            if (ctype_digit($word)) {
                $stack->push($word);
            } else {
                $fn = $dicts->getWord($word);
                if ($fn) {
                    $fn($stack, $b, $word);
                } else {
                    throw new RuntimeException('Found no word inside : def: ' . $word);
                }
            }
        }
    });
});
$dicts->addDict('main', $mainDict);

$dsn = 'mysql:host=localhost;dbname=fact2;charset=utf8mb4';
$username = 'olle';
$password = 'password';
$db = new PDO($dsn, $username, $password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// OBS New dicts
$dicts = new Dicts();
$rootDict = new Dict();

// Dot operator
$rootDict->addWord('.', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    echo $a;
});

// todo <- ?
$rootDict->addWord('swap', function(Stack_ $stack, StringBuffer $buffer, string $word) use ($rootDict, $db) {
    // nothing
});

// todo use new:
$rootDict->addWord('new', function(Stack_ $stack, StringBuffer $buffer, string $word) use ($rootDict, $db) {
    // nothing
});

$dicts->addDict('root', $rootDict);

$stack = new Stack_();
while (true) {
    echo "? ";
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
                echo "> ";
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
                $function = isset($frame['function']) ? $frame['function'] : '';
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
