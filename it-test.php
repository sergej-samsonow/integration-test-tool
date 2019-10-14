#!/usr/bin/env php

<?php

# php it-test --category=010-management --suite=020-terminal-management.xml --skip-first=4

interface CommandResolver {
    public function resolved(Command $command);
}

class Report {

    private $lineWidth;
    private $header;
    private $headerPrinted;
    private $statusIndent;
    private $warnings;

    public function __construct($header) {
        $this->lineWidth        = 120;
        $this->statusIndent     = $this->lineWidth - 18;
        $this->headerPrinted    = false;
        $this->header           = $header;
        $this->warnings         = [];
    }

    private function printHeader() {
        if ( ! $this->headerPrinted) {
            $this->nl();
            $this->spl("%{$this->lineWidth}s", [$this->header]);
            $this->spl("%{$this->lineWidth}s", [date('m.d.y H:i:s')]);
            $this->printWarnings();
            $this->nl();
            $this->spl("%-{$this->statusIndent}s Warning     State", ['Test']);
            $this->fl();
        }
        $this->headerPrinted = true;
    }

    private function printWarnings() {
        if (empty($this->warnings)) {
            return;
        }
        $counter = 1;
        $this->nl();
        $this->pl('Warnings:');
        $this->nl();
        foreach ($this->warnings as $warning) {
            $this->spl('%4d. %s', [$counter, $warning]);
            $counter ++;
        }
        $this->warnings = [];
    }

    private function nl() {
        $this->pl('');
    }

    private function fl() {
        $this->pl(str_repeat('-', $this->lineWidth));
    }

    private function pl($content) {
        echo $content . PHP_EOL;
    }

    private function spl(string $template, array $data) {
        $this->pl(vsprintf($template, $data));
    }

    public function warning($warning) {
        $this->warnings[] = $warning;
    }

    public function category($folder) {
        $this->printHeader();
        $this->nl();
        $this->pl($folder);     
    }

    public function suite($suite) {
        $this->pl("    $suite");
    }

    public function test(string $name, Context $context) {
        $state      = sprintf("%10s", $context->isFailed()      ? 'FAIL' : 'OK');
        $warnings   = sprintf("%7s",  empty($this->warnings)    ? ' '    : $this->warnings);
        $this->spl("%-{$this->statusIndent}s $warnings$state",  ["        $name"]);
        if ($context->isFailed() || ! empty($this->warnings)) {
            $this->fl();
            $this->printWarnings();
            if ($context->isFailed()) {
                $this->nl();
                $description = $context->getExpected()->getDecription();
                $this->pl("Expected - $description: ");
                $this->pl($context->getExpected()->perttyFormat());
                $this->nl();
                $this->pl('Recivied:');
                $this->pl($context->getContent()->perttyFormat());
                $this->nl();
            }
            $this->fl();
        }
    }
    public function __destruct() {
        $this->printHeader();
    }
}

class Configuration {

    private $databases;
    private $services;
    private $report;
    private $argv;
    private $category;
    private $suite;
    private $skipFirst;

    public function __construct($env, $argv) {
        $this->argv      = $argv;
        $this->databases = [];
        $this->services  = [];
        $this->skipFirst = -1;
        $this->report    = new Report($env->description);
        foreach ($env->service as $name => $component) {
            if ($component->driver == 'database.mysql') {
                $this->addToDatabases($name, new Database($component)); 
            }
            else {
                $this->addToServices($name, new Service($component)); 
            }
        }       
        $this->parseArgv();
    }

    private function parseArgv() {

        foreach ($this->argv as $param) {
            if (mb_strpos($param, '--category=') === 0) {
                $this->category = mb_substr($param, mb_strlen('--category='));
            }
        }

        if ( ! empty($this->category)) {
            foreach ($this->argv as $param) {
               if (mb_strpos($param, '--suite=') === 0) {
                    $this->suite = mb_substr($param, mb_strlen('--suite='));
                }
            }
        }

        if ( ! empty($this->suite)) {
            foreach ($this->argv as $param) {
                if (mb_strpos($param, '--skip-first=') === 0) {
                    $this->skipFirst = (int) mb_substr($param, mb_strlen('--skip-first='));
                }
            }
        }
    }

    private function addToDatabases($name, $database) {
        $this->databases[$name] = $database;
    }

    private function addToServices($name, $service) {
        $this->services[$name] = $service;
    }

    public function getDatabase($name) {
        return $this->databases[$name];
    }

    public function getService($name) {
        return $this->services[$name];
    }

    public function prepare(Context $context) {
        foreach ($this->databases as $database) {
            $database->prepare();
        }

        foreach ($this->services as $service) {
            $service->prepare();
        }
    }

    public function getReport() {
        return $this->report;
    }

    public function enabledCategory($category) {
        return empty($this->category) || $this->category == $category;
    }

    public function enabledSuite($suite) {
        return empty($this->suite) || $this->suite == $suite;
    }

    public function skipFirst() {
        return $this->skipFirst;
    }
}

class Service {

    private $base;
    private $prepare;

    public function __construct($env) {
        $this->base = $env->base;
        $this->base = trim($this->base, '/');
        if (isset($env->prepare)) {
            $this->prepare = sprintf('%s/%s', $this->base, ltrim($env->prepare, '/'));
        }
    }

    public function getBase() {
        return $this->base;
    }

    public function prepare() {
        if (empty($this->prepare)) {
            return;
        }
        $handle = curl_init($this->prepare);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_exec($handle);
        curl_close($handle);
    }
}

class Database {

    private $pdo;
    private $reset;

    public function __construct($env) {
        $this->pdo   = new PDO($env->conn, $env->user, $env->pass, $env->opts);
        $this->reset = sprintf('mysql -u%s -p%s -h%s -P%s %s < %s 2>&1',
            $env->user, $env->pass, $env->host, $env->port, $env->name, $env->dump);
    }  

    public function prepare() {
        $result = `{$this->reset}`;   
    }
}

class Unknow {

}

class Expected {

    private $expectation;
    private $section;
    private $body;

    public function __construct($expected) {
        $this->section = '/';
        $this->body = "$expected";
        foreach ($expected->attributes() as $name => $value) {
            $name  = "$name";
            $value = "$value";
            if ($name == 'that')    $this->expectation = $value;
            if ($name == 'section') $this->section     = $value;
        }
    }

    public function getExpectation() {
        return $this->expectation;
    }

    public function getSection() {
        return $this->section;
    }

    public function execute(Context $context, Configuration $configuration) {
        $section    = $context->applyVariables($this->section);
        $body       = $context->applyVariables($this->body);
        $content    = $context->getContent();
        $expected   = new Content($this->expectation, 'json', $body);
        if ($content->getObjectSection($section) != $expected->getObject()) {
            $context->fail($expected);
        }
    }
}

class Content {

    private $description;
    private $type;
    private $value;
    private $converted;
    private $errors;
    private $data;

    public function __construct($description, $type, $value) {
        $this->description  = $description;
        $this->type         = $type;
        $this->value        = $value;
        $this->converted    = false;
        $this->data         = null;
        $this->convert();
    }

    public function getDecription() {
        return $this->description;
    }

    private function convertFromJson() {

        // nothing object is null
        if ($this->value == null) {
            $this->data = null;
            return;
        }

        // array
        $data = trim($this->value);
        if ($data == '[]') {
            $this->data = [];
            return;
        }

        // null value (as string)
        if (mb_strtolower($data) == 'null') {
            $this->data = null;
            return;
        }

        // is object
        $json = json_decode($data);
        if ($json != null) {
            $this->data = $json;
            return;
        }

        // simple type: numberic or string
        $this->data = $data;
        $data = mb_strtolower($data);
        if      ($data == 'false') $this->data = false;
        else if ($data == 'true')  $this->data = true;
        else if (is_int($data))    $this->data = (int)$data;
        else if (is_float($data))  $this->data = (float)$data;
        else if (is_double($data)) $this->data = (double)$data;
    }

    private function convert() {
        if ($this->type == 'json') {
            $this->convertFromJson();
        }
    }

    public function getObjectSection($section) {

        $elements   = $this->getObject();
        $section    = trim($section);

        if (empty($section)) {
            return $elements;
        }

        if (empty($elements) || is_scalar($elements)) {
            return null;
        }

        if ($section == '/') {
            return $elements;
        }

        foreach (explode('/', $section) as $token) {
            $token = trim($token);
            if ($token === '')      continue;
            if (is_numeric($token)) $token = (int)$token;
            $keys[] = $token;
        }

        $elements = array_reduce($keys, function($element, $key) {
            if (is_array($element) && isset($element[$key])) {
                return $element[$key];
            }
            if (is_object($element) && isset($element->{$key})) {
                return $element->{$key};
            }
            return null;
        }, $elements);
        return $elements;
    }

    public function getObject() {
        return $this->data;
    }

    public function getType() {
        return $this->type;
    }

    public function getValue() {
        return $this->value;
    }

    public function perttyFormat() {
        $data = $this->getObject();
        if (is_scalar($data)) {
            if ($data === true) {
                return 'true';
            }
            else if ($data === false) {
                return 'false';
            }
            return $data;
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

class CommandBase {

    private $type;
    private $path;
    private $service;
    private $body;
    private $warnings;
    private $result;
    private $resultType;

    public function __construct($type) {
        $this->type = $type;
        $this->warnings = [];
    }

    public function getType() {
        return $this->type;
    }

    public function setPath($path) {
        $this->path = $path;
    }

    public function getPath() {
        return $this->path;
    }

    public function setService($service) {
        $this->service = $service;
    }

    public function getService() {
        return $this->service;
    }

    public function setBody($body) {
        $this->body = $body;
    }

    public function getBody() {
        return $this->body;
    }

    public function execute() {
    }

    public function setResult($result) {
        $this->result = $result;
    }

    public function getResult() {
        return $this->result;
    }

    public function addToWarnings($warning) {
        $this->warnings[] = $warning;
    }

    public function getWarnings() {
        return $this->warnings;
    }

    public function getResultType() {
        return $this->resultType;
    }

    public function setResultType($type) {
        $this->resultType = $type;
    }
}

class CommandUnknown extends CommandBase {

    public function __construct($type) {
        parent::__construct($type);
        $this->addToErrors("Command: $type unknown");
    }
}

class CommandForDb extends CommandBase {

}

class CommandForHttp extends CommandBase {

    private $curl;
    private $post;
    private $get;
    private $put;
    private $delete;

    private function checkConditions() {
        $type = $this->getType();
        $this->post     = mb_strpos($type, 'post'  ) !== false;
        $this->get      = mb_strpos($type, 'get'   ) !== false;
        $this->put      = mb_strpos($type, 'put'   ) !== false;
        $this->delete   = mb_strpos($type, 'delete') !== false;
        $this->json     = mb_strpos($type, 'json'  ) !== false;
    }

    private function getUrl() {
        $service = $this->getService();
        return sprintf('%s/%s', rtrim($service->getBase()), ltrim($this->getPath()));
    }

    private function createCurlObjct() {
        $this->curl = curl_init($this->getUrl());
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
    }

    private function configureRequestType() {
        if ($this->post) {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($this->curl, CURLOPT_POSTFIELDS,    $this->getBody());
        }
        else if ($this->put) {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($this->curl, CURLOPT_POSTFIELDS,    $this->getBody());
        }
        else if ($this->delete) {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
    }

    private function configureHeaders() {
        if (($this->post && $this->json) || ($this->put && $this->json)) {
            curl_setopt($this->curl, CURLOPT_HTTPHEADER,   
                    ['Content-Type: application/json']);
        }
    }

    private function callAndParseResult() {
        $this->setResult(curl_exec($this->curl));
        curl_close($this->curl);
        if ($this->json) {
            $this->setResultType('json');
        }
    }

    public function execute() {
        $this->checkConditions(); 
        $this->createCurlObjct();
        $this->configureRequestType();
        $this->configureHeaders();
        $this->callAndParseResult();
    }
}

class Command {

    private $type;
    private $service;
    private $path;
    private $executor;
    private $body;

    public function __construct($command) {
        $this->body = "$command";
        foreach ($command->attributes() as $name => $value) {
            $name  = "$name";
            $value = "$value";
            if ($name == 'type'   ) $this->type     = $value;
            if ($name == 'service') $this->service  = $value;
            if ($name == 'path'   ) $this->path     = $value;
            if (mb_strpos($this->type, 'http') === 0) {
                 $this->executor = new CommandForHttp($this->type);
            }
            else if (mb_strpos($this->type, 'sql') === 0) {
                 $this->executor = new CommandForDb($this->type);
            }
            else {
                 $this->executor = new CommandUnknown($this->type);
            }
        }
    }

    public function getType() {
        return $this->type;
    }

    public function getService() {
        return $this->service;
    }

    public function getPath() {
        return $this->path;
    }

    public function execute(Context $context, Configuration $configuration) {
        if ($this->executor instanceof CommandForHttp) {
            $this->executor->setService($configuration->getService($this->service));
        }
        else if ( $this->executor instanceof CommandForDb) {
            $this->executor->setService($configuration->getDatabase($this->service));
        }
        $path = $context->applyVariables($this->path);
        $body = $context->applyVariables($this->body);
        $this->executor->setPath($path);
        $this->executor->setBody($body);
        $this->executor->execute();
        $report = $configuration->getReport();
        foreach ($this->executor->getWarnings() as $warning) {
            $report->warning($warning);
        }
        $context->update(new Content(
            "Type: {$this->type} Service: {$this->service} Path: {$this->path}",
            $this->executor->getResultType(),
            $this->executor->getResult()));
    }
}

class Assign {

    private $variable;
    private $const;
    private $section;
    private $date;

    public function __construct($assign) {
        foreach ($assign->attributes() as $name => $value) {
            $name  = "$name";
            $value = "$value";
            if (     $name == 'var'    ) $this->variable = $value;
            if (     $name == 'const'  ) $this->const    = $value;
            else if ($name == 'section') $this->section  = $value;
            else if ($name == 'date'   ) $this->date     = $value;
        }
    }

    public function execute(Context $context, Configuration $configuration) {
        if ( ! empty($this->const) ) {
            $context->setVariable($this->variable, $context->applyVariables($this->const));
        }
        else if ( ! empty($this->section)) {
            $content = $context->getContent();
            $context->setVariable($this->variable,
                $content->getObjectSection($context->applyVariables($this->section)));
        }
        else if ( ! empty($this->date)) {
            $context->setVariable($this->variable, date($context->applyVariables($this->date)));
        }
    }
}

function parse_code_element($element) {
    $name = ''.$element->getName();
    switch ($name) {
        case 'assign'   : return new Assign($element);
        case 'command'  : return new Command($element);
        case 'expected' : return new Expected($element);
        default         : return new Unknow();
    }
}

class Macro {

    private $suite;
    private $name;
    private $blocks;

    public function __construct($suite, $macro) {
        $this->suite  = $suite;
        $this->name   = "{$macro['name']}";
        $this->blocks = [];
        foreach ($macro->children() as $name => $element) {
            $name = "$name";
            if ($name == 'macro') {
                $key = "{$element['name']}";
                foreach ($this->suite->getMacroBlocks($key) as $block) {
                    $this->blocks[] = $block;
                }
            }
            else {
                $this->blocks[] = parse_code_element($element);           
            }
        }
    }

    public function getName() {
        return $this->name;
    }

    public function getBlocks() {
        return $this->blocks;
    }
}

class Tokenizer {
    private $variables;
    private $content;
    private $interpolated;

    public function __construct($variables, $content) {
        $this->variables    = $variables;
        $this->content      = $content;
        $this->interpolated = '';
    }

    private function isConstant() {
        return mb_strpos($this->content, '$') === false;
    }

    private function interpolate() {
        $tokens = token_get_all(sprintf('<?php
echo <<<EOD
%s
EOD;
', $this->content));           // TODO attention! remove last HEREDOC newline added there
        array_shift($tokens); // T_OPEN_TAG
        array_shift($tokens); // T_ECHO
        array_shift($tokens); // T_WHITESPACE
        array_shift($tokens); // T_START_HEREDOC
        array_pop($tokens);   // T_END_HEREDOC
        array_pop($tokens);   // STRING ;
        array_pop($tokens);   // T_WHITESPACE
        foreach ($tokens as $i => $token) {
            $current = $token[1];
            if ($token[0] == 320) { // T_VARIABLE
                $name    = ltrim($token[1], '$');
                $current = $this->variables[$name] ?? '';
            }
            $this->interpolated .= $current;
        }

        // see TODOblock above - remove HEREDOC new line
        // (is this comment entry above still vaid? i'm not sure)
        $this->interpolated = trim($this->interpolated);   
    }

    public function interpolated() {
        if ($this->isConstant()) {
            return $this->content;
        }
        $this->interpolate();
        return $this->interpolated;
    }
}

class Context {

    private $variables;
    private $history;
    private $content;
    private $expected;

    public function __construct() {
        $this->variables          = [];
        $this->history            = [];
    }

    public function setVariable($name, $value) {
        $this->variables[$name] = $value;
    }

    public function applyVariables($content) {
        $tokenizer = new Tokenizer($this->variables, $content);
        return $tokenizer->interpolated();
    }

    public function update(Content $content) {
        if ($this->content instanceof Content) {
            $this->history[] = $this->content;
        }
        $this->content = $content;
    }

    public function getContent() {
        return $this->content;
    }

    public function fail(Content $expected) {
        $this->expected = $expected;
    }

    public function isFailed() {
        return $this->expected != null;
    }

    public function getExpected() {
        return $this->expected;
    }
}

class Test {

    private $name;
    private $configuration;
    private $suite;
    private $blocks;
    private $context;

    public function __construct($suite, $test) {
        $this->configuration  = $suite->getConfiguration();
        $this->suite          = $suite;
        $this->blocks         = [];
        $this->context        = new Context();
        $this->name           = "{$test['name']}";
        foreach ($test->children() as $name => $element) {
            $name = "$name";
            if ($name == 'macro') {
                foreach ($this->suite->getMacroBlocks("{$element["name"]}") as $block) {
                    $this->blocks[]  = $block;
                }
            }
            else {

                $this->blocks[] = parse_code_element($element);
            }
        }
    }

    public function execute() {
        $this->configuration->prepare($this->context);
        foreach ($this->blocks as $block) {
            $block->execute($this->context, $this->configuration);
            if ($this->context->isFailed()) {
                break;
            }
        }
        $report = $this->configuration->getReport();
        $report->test($this->name, $this->context);
    }
}

class Suite {

    private $continueOnFail;
    private $configuration;
    private $macros;
    private $tests;

    public function __construct($configuration, $suite) {
        $this->continueOnFail   = false;
        $this->configuration    = $configuration;
        $this->macros           = [];
        $this->tests            = [];
        foreach ($suite->macros as $macros) {
            foreach ($macros->macro as $macro) {
                $macro =  new Macro($this, $macro);
                $this->macros[$macro->getName()] = $macro;
            }
        }

        foreach ($suite->tests->attributes() as $name => $value) {
            $name   = "$name";
            $value  = mb_strtolower("$value");
            if ($name == 'continueOnFail') {
                $this->continueOnFail = ($value == 'true');
            }
        }

        $skip = $configuration->skipFirst();
        foreach ($suite->tests as $tests) {
            foreach ($tests->test as $test) {
                if ($skip > 0) {
                    $skip--;
                    continue;
                }
                $this->tests[] = new Test($this, $test);
            }
        }
    }  

    public function getConfiguration() {
        return $this->configuration;
    }

    public function getMacroBlocks($name) {
        return isset($this->macros[$name]) ? $this->macros[$name]->getBlocks() : [];
    }
 
    public function execute() {
        foreach ($this->tests as $test) {
            $test->execute();
        }
    }
}

function filter_files($folder) {
    return array_filter(scandir($folder), function($v) { return $v != '.' &&  $v != '..'; });
}

function join_path($parts) {
    if ( ! is_array($parts)) {
        $parts = [$parts];
    }
    $path = '';
    foreach ($parts as $part) {
        $path = $path . DIRECTORY_SEPARATOR . $part;
    }
    return ltrim($path, DIRECTORY_SEPARATOR);
}

 
class Itom {
    private $error;
    private $model;
    private $variables;
    private $argv;
    private $env;

    public function __construct($argv) {
        $this->argv = $argv;
        array_shift($this->argv);
    }
    private function interpolate($value) {
        $tokenizer = new Tokenizer($this->variables, $value);
        return $tokenizer->interpolated();
    }
    private function parseProperties() {
        foreach ($this->model->variables as $variables) {
            foreach ($variables as $key => $value) {
                $name                   = "$key";
                $defaultValue           = "$value";
                $envName                = $this->interpolate("{$value['env']}");
                $defaulValue            = $this->interpolate("{$value}");
                $envValue               = getenv($envName);
                $cmdParameter           = FALSE;

                foreach ($this->argv as $parameter) {
                    if (mb_strpos($parameter, "--D$name")  === 0) {
                        list($prefix, $cmdParameter) = explode('=', $parameter);
                        $cmdParameter = $this->interpolate("{$cmdParameter}");
                        break;
                    }
                }
                $varValue = '';
                if ( $cmdParameter !== FALSE ) {
                    $varValue = $cmdParameter;
                }
                else if ( $envValue !== FALSE ) {
                    $varValue = $this->interpolate("$envValue");
                }
                else {
                    $varValue = $defaulValue;
                }
                $this->variables[$name] = $varValue;
            }
        }
    }
    private function parseDatabaseConfiguration($service) {
        $db   = new stdClass(); 
        $db->driver = 'database.mysql';
        $db->name   = $this->interpolate("{$service->name}");
        $db->user   = $this->interpolate("{$service->user}");
        $db->pass   = $this->interpolate("{$service->pass}");
        $db->host   = '127.0.0.1';
        $db->port   = 3306;
        $db->charset= 'utf8';
        $db->opts   = [
            PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES      => false,
        ];
        if (isset($service->connection)) {
            if (isset($service->connection['host'])) {
                $db->host   = $this->interpolate("{$service->connection['host']}");
            }
            if (isset($service->connection['port'])) {
                $db->port   = $this->interpolate("{$service->connection['port']}");
            }
            if (isset($service->connection['reset'])) {
                $db->dump   = $this->interpolate("{$service->connection['reset']}");
            }
            if (isset($service->connection['charset'])) {
                $this->charset = $this->interpolate("{$service->connection['charset']}");
            }
        }
        $db->conn   = "mysql:host={$db->host};port={$db->port};dbname={$db->name};charset={$db->charset}";
        return $db;
    }
    private function parseRestConfiguration($service) {
        $server         = new stdClass(); 
        $server->driver = 'http.rest';
        $server->schema = $this->interpolate("{$service->connection['schema']}");
        $server->host   = $this->interpolate("{$service->connection['host']}");
        $server->port   = $this->interpolate("{$service->connection['port']}");
        if (isset($service->connection['reset'])) {
            $server->reset = $this->interpolate("{$service->connection['reset']}");
        }
        if (    ($server->schema == 'http'  && ("{$server->port}" == "80")  || empty($server->port))
            ||  ($server->schema == 'https' && ("{$server->port}" == "443") || empty($server->port))
            ) {
            $server->base   = "{$server->schema}://{$server->host}";
        }
        else {
            $server->base   = "{$server->schema}://{$server->host}:{$server->port}";
        }
        return $server;
    }
    private function parseServices() {
        foreach ($this->model->services as $services) {
            foreach ($services->service as $service) {
                $id     = $this->interpolate("{$service['id']}");
                $driver = $this->interpolate("{$service['driver']}");
                if (empty($id) || empty ($driver)) {
                    continue;
                }
                if ( ! isset($this->env->service)) {
                    $this->env->service = new stdClass();
                }
                if ( ! isset($this->env->service->{$id})) {
                    $this->env->service->{$id} = new stdClass();
                }

                # workaround as long we haven't realy driver, change later don't match driver or service exactly
                if ($driver === 'database.mysql') {
                    $this->env->service->{$id} = $this->parseDatabaseConfiguration($service);
                }
                else if ($driver === 'http.rest' && isset($service->connection)) {
                    $this->env->service->{$id} = $this->parseRestConfiguration($service);
                }
            }
        }
    }

    public function load() {
        $this->variables = [];
        $pwd = getcwd();
        if ( ! is_file($pwd . DIRECTORY_SEPARATOR . 'itom.xml')) {
            $this->error = sprintf('Integration test model file itom.xml not found in: %s exit(1)', $pwd);
        }
        $this->model = simplexml_load_string((file_get_contents(join_path([$pwd, 'itom.xml']))));
        $this->env = new stdClass();
        $this->parseProperties();
        $this->parseServices();
        $this->env->pwd = $pwd;
        $this->env->testFolder  = sprintf("%s%sintegration-tests", $pwd, DIRECTORY_SEPARATOR);
        $this->env->description = "Intgration Tests: " . $this->env->testFolder;
        if (isset($this->model->description)) {
            $this->env->description = $this->interpolate("{$this->model->description}");
        }
    }

    public function isValid() {
        return empty($this->error);
    }

    public function getError() {
        return $this->error;
    }
    public function getEnv() {
        return $this->env;
    }
}

$itom = new Itom($argv);
$itom->load();
if ( ! $itom->isValid()) {
    echo $itom->getError() . PHP_EOL;
    exit(1);
}

$env = $itom->getEnv();
$configuration = new Configuration($env, $argv);
$report = $configuration->getReport();


$cateogories = filter_files(join_path($env->testFolder));
if (empty($cateogories)) {
    $report->warning("test folder {$env->testFolder} is empty finish now");
    exit(0);
}

foreach ($cateogories as $category) {

    if ( ! $configuration->enabledCategory($category)) continue;
    $suites = filter_files(join_path([$env->testFolder, $category]));
    if (empty($suites)) {
        $report->category("$category - empty not suites defined yey");
    }
    else {
        $report->category($category);
    }
    foreach ($suites as $suite) {
        if ( ! $configuration->enabledSuite($suite)) continue;
        $report->suite($suite);
        $suite = simplexml_load_string((file_get_contents(join_path([$env->testFolder, $category, $suite]))));
        $suite = new Suite($configuration, $suite);
        $suite->execute();
    }
}

exit(0);
