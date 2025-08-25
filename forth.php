<?php

/**
 * @todo soft delete, del, archive
 * @todo roots command, list all entitys that is not another entity
 */

require_once(__DIR__ . "/vendor/autoload.php");

use Latitude\QueryBuilder\Engine\MySqlEngine;
use Latitude\QueryBuilder\QueryFactory;
use function Latitude\QueryBuilder\field;
use function Latitude\QueryBuilder\group;
use function Latitude\QueryBuilder\alias;
use function Latitude\QueryBuilder\func;

$queryBuilder = new QueryFactory(new MySqlEngine());

$s = <<<FORTH
( Pre-defined helper words )
: set-sql only sql ;
: end-sql only main ;
: set-php only php ;
: end-php only main ;
: compliment 1 swap - ;
: % 100 swap * 2 round ;

var report          \ Create variable report
new table report !  \ Save table data structure to new variable
report @ "Article report" set title
report @ "articles" set table

var joins           \ New variable for SQL joins
new list joins !
var join
new table join !
join @ "categories" set table
join @ "articles.cat_id = categories.id" set on
joins @ join @ push
report @ joins @ set joins
unset joins
unset join

var columns         \ New variable for report columns
new list columns !  \ Create list

var column
new table column !
column @ "Artnr" set title
column @ "article_id" set select
columns @ column @ push

new table column !
column @ "Diff" set title
column @ "diff" set as
column @ set-sql 
    "purchase_price" "selling_price" / compliment %
end-sql set select
columns @ column @ push

report @ columns @ set columns
unset columns
unset column

var rows
run-query rows !
report @ rows @ set rows

var totals
new list totals !

var total
new table total !
total @ "diff" set for
total @ set-php
    rows @ sum diff 
    count rows
    /
end-php set result
totals @ total @ push

new table total !
total @ "diff_perc" set for
total @ set-php
    rows @ sum diff_perc
    count rows
    /
end-php set result

totals @ total @ push
report @ totals @ set totals
unset totals
unset total
FORTH;

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
        /*
        if ($this->buffer[$this->pos] === '(' && $this->inside_quote === 0) {
            $endComment = strpos($this->buffer, ')', $this->pos + 1);
            // TODO: What if result is a new comment?
            $result = substr($this->buffer, $this->pos, $endComment - $this->pos + 1);
            $this->pos = $endComment + 2;
        }
        */
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

interface StackElement {}

class Attribute_
{
    //public static function 
}

class Entity implements StackElement
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
}

/**
 * Main eval loop
 */
function eval_buffer(StringBuffer $buffer, Dicts $dicts): SplStack
{
    $stack  = new SplStack();
    while ($word = $buffer->next()) {
        $fn = $dicts->getWord($word);
        if (trim($word, '"') !== $word) {
            $stack->push($word);
            // Digit
        } elseif (ctype_digit($word)) {
            $stack->push($word);
            // Execute dict word
        } elseif ($fn) {
            $fn($stack, $buffer, $word);
        } else {
            $stack->push($word);
            //throw new RuntimeException('Word is not a string, not a number, and not in dictionary: ' . $word);
        }
    }
    return $stack;
}

/**
 * Parsing the string buffer populates the environment, which is returned.
 *
 * @return string
 */
function getSelectFromColumns(SplStack $cols)
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

$mainDict->addWord('run-query', function($stack, $buffer, $word) use ($mem) {
    $report = $mem['report'];
    //var_dump($report);die;
    $select = getSelectFromColumns($report['columns']);
    $table = $report['table'];
    $joins = $report['joins'];
    $sql  = "SELECT $select FROM $table";
    // todo: run query here
    $data = [
        [
            'id' => 1,
            'diff' => 2,
            'diff_perc' => 11
        ],
        [
            'id' => 2,
            'diff' => 4,
            'diff_perc' => 12
        ],
        [
            'id' => 3,
            'diff' => 6,
            'diff_perc' => 13
        ]
    ];
    $stack->push($data);
});
$mainDict->addWord('!', function($stack, $buffer, $word) use ($mem) {
    $name = $stack->pop();
    $value = $stack->pop();
    $mem[$name] = $value;
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
            $stack->push(new SplStack());
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

/**
 * @todo search by id
 */
function get_entity($db, $name)
{
    $sql = "SELECT * FROM entity WHERE name = :name";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $entity = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch as an associative array
    if ($entity) {
        // Fetch all is_a
        $subject_id = (int) $entity['id'];
        $sql = <<<SQL
SELECT * FROM entity
JOIN is_a ON entity.id = is_a.object_id AND is_a.subject_id = $subject_id
SQL;
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $is = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all as associative arrays
        $entity['is_a'] = $is ?? [];

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
        $entity['attribute'] = $attrs ?? [];
    }
    return $entity;
}

function get_attr($db, $entityId, $attrName)
{
    $sql = <<<SQL
        SELECT * FROM attribute
        JOIN entity ON entity.id = attribute.entity_id
        WHERE attribute.name = :name
SQL;

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':name', $attrName, PDO::PARAM_STR);
    $stmt->execute();
    $attr = $stmt->fetch(PDO::FETCH_ASSOC);
    return $attr;
}

function set_attr_value(&$attr, $value)
{
}

function update_attr($db, $attr)
{
}

// OBS New dicts
$dicts = new Dicts();
$rootDict = new Dict();

// Dot operator
$rootDict->addWord('.', function ($stack, $buffer, $word) {
    $a = $stack->pop();
    echo $a;
});

// Put operator
$rootDict->addWord('put', function($stack, $buffer, $word) use ($rootDict, $db) {
    $name = $buffer->next();
    if (empty($name)) {
        echo "ERROR: Nothing to put";
        return;
    }

    $sql = "INSERT INTO entity VALUES (null, :name, null, NOW(), 0)";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $lastInsertId = $db->lastInsertId();
    echo "Added entity $name, id " . $lastInsertId;
});

$rootDict->addWord('get', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $buffer->next();
    if (!is_string($a)) {
        throw new Exception('Expected string');
    }
    $entity = Entity::findByName($db, $a);
    if (empty($entity)) {
        return;
    }
    print_r($entity);
    $stack->push($entity);
});

// is-a operator
$rootDict->addWord('is-a', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $stack->pop();
    $b = $buffer->next();
    if (empty($a) || empty($b)) {
        echo "ERROR: Missing arguments";
        return;
    }

    $subject = get_entity($db, $a);
    if (empty($subject)) {
        echo "ERROR: Found no subject";
        return;
    }
    $object = get_entity($db, $b);
    if (empty($object)) {
        echo "ERROR: Found no object";
        return;
    }

    $sql = "INSERT INTO is_a VALUES (null, :subject_id, :object_id, NOW(), 0)";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':subject_id', $subject['id'], PDO::PARAM_INT);
    $stmt->bindParam(':object_id', $object['id'], PDO::PARAM_INT);
    $stmt->execute();
    $lastInsertId = $db->lastInsertId();
    echo "Added is-a, id " . $lastInsertId;
});

// has-a operator
$rootDict->addWord('has-a', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $stack->pop();
    $b = $buffer->next();
    if (empty($a) || empty($b)) {
        echo "ERROR: Missing arguments";
        return;
    }

    $subject = get_entity($db, $a);
    if (empty($subject)) {
        echo "ERROR: Found no subject";
        return;
    }
    $object = get_entity($db, $b);
    if (empty($object)) {
        echo "ERROR: Found no object";
        return;
    }

    $sql = "INSERT INTO has_a VALUES (null, :subject_id, :object_id, null, NOW(), 0)";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':subject_id', $subject['id'], PDO::PARAM_INT);
    $stmt->bindParam(':object_id', $object['id'], PDO::PARAM_INT);
    $stmt->execute();
    $lastInsertId = $db->lastInsertId();
    echo "Added has-a, id " . $lastInsertId;
});


// list operator
// example: list hg command
$rootDict->addWord('list', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $buffer->next();
    $b = $buffer->next();

    if (empty($a)) {
        $sql = "SELECT * FROM entity LIMIT 1000";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $entitys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entitys as $entity) {
            echo $entity['id'] . "\t" . $entity['name'] . "\t" . $entity['description'] . PHP_EOL;
        }
        return;
    }
    $entity1 = get_entity($db, $a);
    if (empty($entity1)) {
        echo "ERROR: Found no entity";
        return;
    }
    $subject_id = (int) $entity1['id'];

    // One param version
    if (!empty($a) && empty($b)) {
        $sql = <<<SQL
SELECT *
FROM entity
JOIN is_a ON entity.id = is_a.subject_id AND is_a.object_id = $subject_id
SQL;
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $entitys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entitys as $entity) {
            echo $entity['id'] . "\t" . $entity['name'] . "\t" . $entity['description'] . PHP_EOL;
        }
        return;
    }

    if (empty($a) || empty($b)) {
        echo "ERROR: Missing arguments";
        return;
    }

    if (empty($entity1)) {
        echo "ERROR: Found no entity";
        return;
    }
    $entity2 = get_entity($db, $b);
    if (empty($entity2)) {
        echo "ERROR: Found no entity";
        return;
    }
    $subject_id = (int) $entity1['id'];
    $object_id = (int) $entity2['id'];

    $sql = <<<SQL
SELECT *
FROM entity
JOIN is_a ON entity.id = is_a.subject_id AND is_a.object_id = $object_id
JOIN has_a ON has_a.subject_id = $subject_id AND has_a.object_id = entity.id
SQL;

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $entitys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entitys as $entity) {
        echo $entity['id'] . "\t" . $entity['name'] . "\t" . $entity['description'] . PHP_EOL;
    }
});

// desc operator
// describe entity
// set description?
$rootDict->addWord('desc', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $buffer->next();
    if (empty($a)) {
        echo "ERROR: Missing arguments";
        return;
    }
    if (strval(intval($a)) === $a) {
        // search by id
    }
    $entity = get_entity($db, $a);
    if (empty($entity)) {
        echo "ERROR: Found no entity";
        return;
    }
    $newDesc = '';
    while ($word = $buffer->next()) {
        $newDesc .= $word . ' ';
    }
    if ($newDesc) {
        $sql = 'UPDATE entity SET description = :desc WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':desc', $newDesc, PDO::PARAM_STR);
        $stmt->bindParam(':id', $entity['id'], PDO::PARAM_INT);
        $stmt->execute();
        echo 'Updated description';
    } else {
        printf("[%d] %s \"%s\"", $entity['id'], $entity['name'], trim($entity['description']));

        if (count($entity['is_a']) > 0) {
            echo " (";
            $names = "";
            foreach ($entity['is_a'] as $i) {
                $names .= $i['name'] . ", ";
            }
            $names = trim($names, ", ");
            echo $names;
            echo ")";
        }
        echo PHP_EOL;

        if (count($entity['attribute']) > 0) {
            foreach ($entity['attribute'] as $attr) {
                printf("\t%s: %s\n", $attr['name'], $attr['value_int']);
            }
        }

        $subject_id = (int) $entity['id'];
        $sql = <<<SQL
SELECT entity.name AS t1_name, t2.name AS t2_name FROM entity
JOIN has_a ON has_a.subject_id = $subject_id AND has_a.object_id = entity.id
LEFT JOIN is_a ON is_a.subject_id = has_a.object_id
LEFT JOIN entity AS t2 ON is_a.object_id = t2.id
SQL;
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $entitys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entitys as $t) {
            printf("\thas {$t['t1_name']} ({$t['t2_name']})\n");
        }

    }
});

// set-a operator
// set attribute to entity
$rootDict->addWord('set-a', function($stack, $buffer, $word) use ($rootDict, $db, $queryBuilder) {
    $name = $buffer->next();
    $attrName = $buffer->next();
    $attrType = $buffer->next();
    $attrValue = $buffer->next();

    if (empty($name) || empty($attrName) || empty($attrType) || empty($attrValue)) {
        echo "ERROR: Missing arguments\n";
        echo "USAGE: set-a <entity name> <attr name> <attr type> <attr value>";
        return;
    }

    $entity = get_entity($db, $name);
    if (empty($entity)) {
        echo "ERROR: Found no entity";
        return;
    }

    $attr = get_attr($db, $entity['id'], $attrName);
    if ($attr) {
        // check so that type is same, else abort
        // update with new value
    } else {
        $attr = [];
        // save new attribute
        switch ($attrType) {
            case "int":
                $attr['name'] = $attrName;
                $attr['entity_id'] = (int) $entity['id'];
                $attr['value_int'] = (int) $attrValue;
                break;
            // TODO more types
        }
        $query = $queryBuilder->insert("attribute", $attr)->compile();
        $stmt = $db->prepare($query->sql());
        $stmt->execute($query->params());
        $lastInsertId = $db->lastInsertId();
        echo "Added attribute $attrName, id " . $lastInsertId;
    }
});

//$rootDict->addWord('bat', function($stack, $buffer, $word) use ($rootDict, $db) {
    //system("acpi -b");
//});

$rootDict->addWord('tech', function($stack, $buffer, $word) use ($rootDict, $db) {
    $url = 'https://old.reddit.com/r/technology';
    $html = shell_exec("w3m -dump_source '$url'");

    if (isGzipped($html)) {
        $html = gzdecode($html);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $query = "//p[contains(concat(' ', @class, ' '), ' title ')]";
    $paragraphs = $xpath->query($query);

    if ($paragraphs->length > 0) {
        foreach ($paragraphs as $paragraph) {
            echo $paragraph->textContent . "\n";
        }
    } else {
        echo "No paragraph tags with class 'title' found.\n";
    }

});

// site + tag + class
$rootDict->addWord('crawl', function($stack, $buffer, $word) use ($rootDict, $db) {
    $url = $buffer->next();
    $tag = $buffer->next();
    $className = $buffer->next();

    $html = shell_exec("w3m -dump_source '$url'");

    if (isGzipped($html)) {
        $html = gzdecode($html);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    if ($className) {
        $query = "//" . $tag . "[contains(concat(' ', @class, ' '), ' $className ')]";
    } else {
        $query = "//" . $tag;
    }
    $paragraphs = $xpath->query($query);

    if ($paragraphs->length > 0) {
        foreach ($paragraphs as $paragraph) {
            $t = preg_replace('/\s+/', ' ', $paragraph->textContent);
            if ($t) {
                echo $t . "\n";
            }
        }
    } else {
        echo "No $tag tags with class '$className' found.\n";
        echo $query . PHP_EOL;
        echo substr($html, 0, 1000) . PHP_EOL;
    }
});

$rootDict->addWord('search', function($stack, $buffer, $word) use ($rootDict, $db) {
    $a = $buffer->next();
    if (empty($a)) {
        echo "ERROR: Missing arguments";
        return;
    }

    $sql = <<<SQL
        SELECT * FROM entity
        WHERE name LIKE '%$a%'
        ORDER BY id DESC 
        LIMIT 1000
SQL;
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $entitys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($entitys)) {
        echo 'Found noentity';
        return;
    }
    foreach ($entitys as $entity) {
        echo $entity['id'] . "\t" . $entity['name'] . PHP_EOL;
    }
});

$rootDict->addWord('exec', function(SplStack $stack, StringBuffer $buffer, string $word) use ($rootDict, $db) {
    $a = $stack->pop();
    if (empty($a)) {
        return;
    }
    // TODO Check if $a is an entity and is-a command
    $output = shell_exec($a);
    $stack->push($output);
});

$dicts->addDict('root', $rootDict);

while (true) {
    echo "? ";
    $line = trim((string) fgets(STDIN)); // Read a line from stdin

    if (strtolower($line) === 'exit') {
        echo "Exiting loop\n";
        break; // Exit the loop if the user types 'exit'
    }

    if (!empty($line)) {
        try {
            $stack = eval_buffer(new StringBuffer($line), $dicts);
            if ($stack->count() > 0) {
                //while ($stack->count() > 0 && $thing = $stack->pop()) {
                foreach ($stack as $thing) {
                    if (is_string($thing)) {
                        echo $thing;
                        echo PHP_EOL;
                    }
                }
            }
        } catch (RuntimeException $e) {
            echo "ERROR: " . $e->getMessage();
        }
    } else {
        sleep(1);
    }
    echo PHP_EOL;
}
