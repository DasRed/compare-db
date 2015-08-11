<?php
use Zend\Console\Getopt;
use Zend\Console\Exception\RuntimeException;

set_time_limit(0);
ignore_user_abort(false);

$autoloader = null;
foreach ([
	// Local install
	__DIR__ . '/../vendor/autoload.php',
	// Root project is current working directory
	getcwd() . '/vendor/autoload.php',
	// Relative to composer install
	__DIR__ . '/../../../autoload.php'
] as $autoloadFile)
{
	if (file_exists($autoloadFile) === true)
	{
		$autoloader = require $autoloadFile;
		break;
	}
}

// autoload not found... abort
if ($autoloader === null)
{
	fwrite(STDERR, 'Unable to setup autoloading; aborting\n');
	exit(2);
}

try
{
	$opts = new Getopt(array(
		'f|from=w' => 'Databank 1 welche als vorlage dient',
		't|to=w' => 'Databank 2 mit welcher verglichen werden soll',
		'h|host=s' => 'MySQL Host',
		'u|user=s' => 'MySQL User',
		'p|password=s' => 'MySQL Password'
	), $argv);
	$opts->parse();

	if (!count($opts->getOptions()))
	{
		throw new RuntimeException("missing parameters", $opts->getUsageMessage());
	}
}
catch (\Exception $e)
{
	echo $opts->getUsageMessage();
	die();
}

/**
 *
 * @param PDO $pdo
 * @return array
 */
function getTabels(\PDO $pdo)
{
	$result = [];
	foreach ($pdo->query('SHOW TABLE STATUS')->fetchAll(\PDO::FETCH_ASSOC) as $row)
	{
		unset($row['Rows']);
		unset($row['Avg_row_length']);
		unset($row['Data_length']);
		unset($row['Max_data_length']);
		unset($row['Index_length']);
		unset($row['Data_free']);
		unset($row['Auto_increment']);
		unset($row['Create_time']);
		unset($row['Update_time']);
		unset($row['Check_time']);
		unset($row['Checksum']);

		$result[$row['Name']] = $row;
	}

	return $result;
}

function getFields(\PDO $pdo, $tableName)
{
	$result = [];
	foreach ($pdo->query('SHOW FULL COLUMNS FROM ' . $tableName)->fetchAll(\PDO::FETCH_ASSOC) as $row)
	{
		$result[$row['Field']] = $row;
	}

	return $result;
}

function getIndex(\PDO $pdo, $tableName)
{
	$result = [];
	foreach ($pdo->query('SHOW INDEX FROM ' . $tableName)->fetchAll(\PDO::FETCH_ASSOC) as $row)
	{
		unset($row['Cardinality']);

		$result[$row['Key_name']] = $row;
	}

	return $result;
}

function diffForMissingAndToMuch($listA, $listB, $textWhat, $textIn)
{
	$keysA = array_keys($listA);
	$keysB = array_keys($listB);

	foreach (array_diff($keysA, $keysB) as $missingStuff)
	{
		echo 'Missing ' . $textWhat . ' in Database "' . $textIn . '": ' . $missingStuff . PHP_EOL;
	}

	foreach (array_diff($keysB, $keysA) as $toMuchStuff)
	{
		echo 'Following ' . $textWhat . ' in Database "' . $textIn . '" should not exists: ' . $toMuchStuff . PHP_EOL;
	}
}

function compareMeta($metaDataA, $metaDataB, $textWhat, $textIn)
{
	foreach ($metaDataA as $metaKey => $metaValueA)
	{
		if (array_key_exists($metaKey, $metaDataB) === false)
		{
			echo 'Missing ' . $textWhat . ' property in Database "' . $textIn . '": ' . $metaKey . ' = ' . $metaValueA . PHP_EOL;
			continue;
		}
		if ($metaDataB[$metaKey] !== $metaValueA)
		{
			echo 'Difference ' . $textWhat . ' property in Database "' . $textIn . '@' . $metaKey . '": should be "' . $metaValueA . '" but is "' . $metaDataB[$metaKey] . '"' . PHP_EOL;
			continue;
		}
	}
}

$pdoA = new \PDO('mysql:dbname=' . $opts->from . ';host=' . $opts->host, $opts->user, $opts->password, array(
	\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
));
$pdoA->exec('USE ' . $opts->from);

$pdoB = new \PDO('mysql:dbname=' . $opts->to . ';host=' . $opts->host, $opts->user, $opts->password, array(
	\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
));
$pdoB->exec('USE ' . $opts->to);

// Tables
$tablesA = getTabels($pdoA);
$tablesB = getTabels($pdoB);

diffForMissingAndToMuch($tablesA, $tablesB, 'table', $opts->to);
foreach ($tablesA as $tableNameA => $tableDataA)
{
	if (array_key_exists($tableNameA, $tablesB) === false)
	{
		continue;
	}
	compareMeta($tableDataA, $tablesB[$tableNameA], 'table', $opts->to . '.' . $tableNameA);

	foreach ([
		'getFields' => 'field',
		'getIndex' => 'index'
	] as $getter => $text)
	{
		$tableSubA = $getter($pdoA, $tableNameA);
		$tableSubB = $getter($pdoB, $tableNameA);

		diffForMissingAndToMuch($tableSubA, $tableSubB, $text, $opts->to . '.' . $tableNameA);
		foreach ($tableSubA as $tableSubNameA => $tableSubDataA)
		{
			if (array_key_exists($tableSubNameA, $tableSubB) === false)
			{
				continue;
			}

			compareMeta($tableSubDataA, $tableSubB[$tableSubNameA], $text, $opts->to . '.' . $tableNameA . '.' . $tableSubNameA);
		}
	}
}

echo 'Done' . PHP_EOL;
