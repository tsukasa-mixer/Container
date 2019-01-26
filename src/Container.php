<?php

namespace Tsukasa\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use Tsukasa\Container\Exceptions\CircularContainerException;
use Tsukasa\Container\Exceptions\ContainerException;
use Tsukasa\Container\Exceptions\InvalidAttributeException;
use Tsukasa\Container\Exceptions\NotFoundContainerException;

class Container implements ContainerInterface
{
    const DEPENDENCY_VALUE = 1;
    const DEPENDENCY_OBJECT_VALUE_REQUIRED = 3;
    const DEPENDENCY_OBJECT_VALUE_OPTIONAL = 4;
    const DEPENDENCY_REFERENCE_REQUIRED = 5;
    const DEPENDENCY_REFERENCE_OPTIONAL = 6;
    const DEPENDENCY_REFERENCE_LOADED = 7;

    const ARGUMENTS_STRATEGY_CONSTRUCTOR = 1;
    const ARGUMENTS_STRATEGY_CALL = 2;
    protected static $_instances = [];
    /**
     * Fetch references (Interfaces and Classes) from all given definitions with ReflectionClass
     *
     * @var bool
     */
    protected $_fullReference = true;
    /**
     * Automatically pass correct arguments to constructor by type-hint arguments with ReflectionClass
     *
     * @var bool
     */
    protected $_autowire = true;
    /**
     * Default service name, by class name
     *
     * @var array
     */
    protected $_bind = [];
    /**
     * Definitions of services, by id
     *
     * @see Container::addDefinition()
     * @var array
     */
    protected $_definitions = [];
    /**
     * Constructor dependencies, by class name
     *
     * @see Container::fetchConstructorDependencies()
     * @var array
     */
    protected $_constructors = [];
    /**
     * Services ids, by class name
     *
     * @see Container::addReference()
     * @var array
     */
    protected $_references = [];
    /**
     * Class references (list of interfaces and ancestors classes) by class name
     *
     * @see Container::addClassReference()
     * @var array
     */
    protected $_classReferences = [];
    /**
     * Initialised services, by id
     *
     * @var array
     */
    protected $_services = [];
    /**
     * Currently loading elements, for circular dependencies detection
     *
     * @var array
     */
    protected $_loading = [];
    /**
     * Delayed calls of services methods
     *
     * @see Container::addDelayedCall()
     * @var array
     */
    protected $_delayedCalls = [];

    /**
     * Container constructor.
     *
     * @throws ContainerException
     */
    public function __construct()
    {
        if (empty(static::$_instances)) {
            static::$_instances['default'] = $this;
        }

        $this->addService('container', $this);
        $this->addFullReference(self::class, static::class, 'container');
        $this->addFullReference(ContainerInterface::class, static::class, 'container');
    }

    /**
     * Add service to registered services
     *
     * @param string $id
     * @param $service
     * @throws ContainerException
     */
    protected function addService($id, $service)
    {
        if (isset($this->_services[$id])) {
            throw new ContainerException("Can not redeclare already registered service with name {$id}");
        }
        $this->_services[$id] = $service;
    }

    /**
     * Add full references of class and id
     *
     * @param string $classReference
     * @param string $ownerClassName
     * @param string $id
     */
    protected function addFullReference($classReference, $ownerClassName, $id)
    {
        $this->addReference($id, $classReference);
        $this->addClassReference($ownerClassName, $classReference);
    }

    /**
     * Add reference by class name with given service id
     *
     * @param string $id
     * @param string $className
     */
    public function addReference($id, $className)
    {
        if (!isset($this->_references[$className])) {
            $this->_references[$className] = [];
        }
        $this->_references[$className][] = $id;
    }

    /**
     * Add class reference
     *
     * @param string $ownerClassName
     * @param string $classReference
     */
    protected function addClassReference($ownerClassName, $classReference)
    {
        if (!isset($this->_classReferences[$ownerClassName])) {
            $this->_classReferences[$ownerClassName] = [];
        }
        $this->_classReferences[$ownerClassName][] = $classReference;
    }

    /**
     * @param string $name
     *
     * @return static
     * @throws ContainerException
     */
    public static function getInstance($name = 'default')
    {

        if (empty(static::$_instances[$name])) {
            static::$_instances[$name] = new static();
        }

        return static::$_instances[$name];
    }

    /**
     * Add service
     *
     * @param $id
     * @param $service
     * @throws ContainerException
     */
    public function set($id, $service)
    {
        $this->addService($id, $service);
    }

    /**
     * @param bool $fullReference
     */
    public function setFullReference($fullReference)
    {
        $this->_fullReference = $fullReference;
    }

    /**
     * @param bool $autowire
     */
    public function setAutowire($autowire)
    {
        $this->_autowire = (bool)$autowire;
    }

    /**
     * Set config of services
     *
     * @param array $config
     * @throws ContainerException
     */
    public function setConfig(array $config)
    {
        if (isset($config['_references'])) {
            unset($config['_references']);
        }
        return $this->setServices($config);
    }

    /**
     * Set array of definitions
     *
     * Eg:
     *
     * [
     *     // Just class
     *     'request' => [
     *         'class' => \System\Request\HttpRequest
     *     ],
     *
     *     // Or
     *     'cli_request' => \System\Request\CliRequest,
     *
     *     // Class and arguments for constructor
     *     'router' => [
     *         'class' => \System\Router\Router,
     *         'arguments' => [
     *             'base.config.routes'
     *         ]
     *     ]
     *
     *     // And you can describe calls for calls methods after creation and properties for set up default properties
     *     'router' => [
     *         'class' => \MyAmazingComponent,
     *         'calls' => [
     *             'setRequest' => ['@request']
     *             'init' => ['Some string property for method init']
     *         ],
     *         'properties' => [
     *             'someProperty' => 'someValue'
     *         ]
     *     ]
     * ]
     *
     * @param array $definitions
     * @throws ContainerException
     */
    public function setServices(array $definitions)
    {
        foreach ($definitions as $id => $definition) {
            $this->addDefinition($id, $definition);
        }
    }

    /**
     * @param string $id
     * @param string|array $definition
     * @throws ContainerException
     */
    public function addDefinition($id, $definition)
    {
        if (is_string($definition)) {
            $definition = ['class' => $definition];
        }
        if (!is_array($definition)) {
            throw new ContainerException("Definition must be an array or a class name string");
        }
        if (!isset($definition['class'])) {
            throw new ContainerException("Definition must contain a class");
        }
        $options = [
            'arguments',
            'properties',
            'calls'
        ];
        foreach ($options as $option) {
            if (!isset($definition[$option])) {
                $definition[$option] = [];
            }
            if (!is_array($definition[$option])) {
                throw new ContainerException("Definition option {$option} must be an array");
            }
        }
        $this->_definitions[$id] = [
            'class' => $definition['class'],
            'arguments' => $definition['arguments'],
            'properties' => $definition['properties'],
            'calls' => $definition['calls']
        ];
        if ($this->_fullReference) {
            $this->addReferences($id, $definition['class']);
        }
    }

    /**
     * Add references of service
     *
     * @param string $id
     * @param string $className
     */
    protected function addReferences($id, $className)
    {
        foreach ($this->fetchReferences($className) as $classReference) {
            $this->addFullReference($classReference, $className, $id);
        }
    }

    /**
     * Fetch references from class
     *
     * @param $className
     *
     * @return array
     * @throws ContainerException
     */
    protected function fetchReferences($className)
    {
        if ($this->hasClassReferences($className)) {
            return $this->getClassReferences($className);
        }

        $references = [$className];
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            $reflection = null;
        }
        if ($reflection) {
            $references = array_merge($references, $reflection->getInterfaceNames());
        }
        $classParents = class_parents($className);
        if ($classParents) {
            $references = array_merge($references, $classParents);
        }

        return $references;
    }

    /**
     * Check fetched class references
     *
     * @param $className
     * @return bool
     */
    protected function hasClassReferences($className)
    {
        return isset($this->_classReferences[$className]);
    }

    /**
     * Get fetched class references
     *
     * @param $className
     * @return mixed
     * @throws ContainerException
     */
    protected function getClassReferences($className)
    {
        if (!$this->hasClassReferences($className)) {
            throw new ContainerException("References of class {$className} are unknown");
        }
        return $this->_classReferences[$className];
    }

    /**
     * Get service by referenced class
     *
     * @param $className
     *
     * @return mixed
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    public function getByReference($className)
    {
        $id = $this->getIdByReference($className);
        return $this->get($id);
    }

    /**
     * Get service id by referenced class
     *
     * @param $className
     * @return mixed
     * @throws NotFoundContainerException
     */
    protected function getIdByReference($className)
    {
        if (!$this->hasReference($className)) {
            throw new NotFoundContainerException("There is no services that referenced with class {$className}");
        }
        return reset($this->_references[$className]);
    }

    /**
     * Check that class name is referenced by service
     *
     * @param $className
     * @return bool
     */
    public function hasReference($className)
    {
        return isset($this->_references[$className]);
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return mixed Entry.
     * @throws NotFoundContainerException
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundContainerException
     */
    public function get($id)
    {
        if (isset($this->_services[$id])) {
            return $this->_services[$id];
        }
        if (!isset($this->_definitions[$id]) && isset($this->_references[$id])) {
            $id = reset($this->_references[$id]);

            if (isset($this->_services[$id])) {
                return $this->_services[$id];
            }
        }
        if (!isset($this->_definitions[$id])) {
            throw new NotFoundContainerException("There is no service with id {$id}");
        }
        return $this->build($id);
    }

    /**
     * Build service
     *
     * @param $id
     * @return mixed
     * @throws CircularContainerException
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function build($id)
    {
        if (isset($this->_loading[$id])) {
            throw new CircularContainerException("Circular dependency detected with services: " . implode(', ', array_keys($this->_loading)));
        }
        $this->_loading[$id] = true;

        $definition = $this->_definitions[$id];
        $className = $definition['class'];
        $object = $this->make($className, $definition['arguments'], $this->_autowire);

        foreach ($definition['properties'] as $name => $value) {
            $object->{$name} = $value;
        }

        if ($definition['calls']) {
            foreach ($definition['calls'] as $method => $attributes) {
                if (is_numeric($method)) {
                    if (is_string($attributes)) {
                        $method = $attributes;
                        $attributes = [];
                    } elseif (is_array($attributes) && count($attributes) === 1) {
                        if (($_method = key($attributes)) && is_string($_method)) {
                            $method = $_method;
                            $attributes = current($attributes);
                        }
                    }
                }
                $this->call($id, $object, $method, $attributes);
            }
        }

        $this->addService($id, $object);

        $this->processDelayedCalls($id);

        unset($this->_loading[$id]);

        return $object;
    }

    /**
     * Make instance of class with constructor arguments
     *
     * @param string $className
     * @param array $attributes
     * @param bool $autowire
     *
     * @return mixed
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function make($className, array $attributes = [], $autowire = true)
    {
        $dependencies = null;
        if ($autowire) {
            $dependencies = $this->fetchConstructorDependencies($className);
        }
        $parameters = $this->buildParameters($attributes);
        $arguments = $this->buildFunctionArguments($dependencies, $parameters);

        if ($arguments) {
            return new $className(...$arguments);
        }

        return new $className;
    }

    /**
     * Read constructor dependencies as array
     *
     * @param $className
     * @return array
     * @throws ReflectionException
     */
    protected function fetchConstructorDependencies($className)
    {
        if (!isset($this->_constructors[$className])) {
            $reflection = new ReflectionClass($className);
            $dependencies = [];
            $constructor = $reflection->getConstructor();
            if ($constructor) {
                $dependencies = $this->fetchFunctionDependencies($constructor);
            }
            $this->_constructors[$className] = $dependencies;
        }
        return $this->_constructors[$className];
    }

    /**
     * Fetch dependencies from function by reflection
     *
     * @param ReflectionFunctionAbstract $reflection
     * @return array Dependencies
     */
    protected function fetchFunctionDependencies(ReflectionFunctionAbstract $reflection)
    {
        $dependencies = [];
        foreach ($reflection->getParameters() as $param) {
            $value = null;
            $type = self::DEPENDENCY_VALUE;

            if ($param->isVariadic()) {
                break;
            }

            if ($c = $param->getClass()) {
                $type = self::DEPENDENCY_OBJECT_VALUE_REQUIRED;
                if ($param->allowsNull()) {
                    $type = self::DEPENDENCY_OBJECT_VALUE_OPTIONAL;
                }
                $value = $c->getName();
            } elseif ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();
            }

            $dependencies[] = [
                'type' => $type,
                'value' => $value,
                'name' => $param->getName()
            ];
        }
        return $dependencies;
    }

    /**
     * Build function attributes for type-value representation
     *
     * @param array $attributes
     * @return array
     */
    protected function buildParameters(array $attributes = [])
    {
        $parameters = [];
        foreach ($attributes as $key => $attribute) {
            list($type, $value) = $this->buildParameter($attribute);
            $parameters[$key] = [
                'type' => $type,
                'value' => $value
            ];
        }
        return $parameters;
    }

    /**
     * Fetching attribute value
     *
     * @param $value
     * @return array
     */
    protected function buildParameter($value)
    {
        $type = self::DEPENDENCY_VALUE;
        if (\is_string($value) && 0 === strpos($value, '@')) {
            $type = self::DEPENDENCY_REFERENCE_REQUIRED;
            if (0 === strpos($value, '@!')) {
                $value = substr($value, 2);
                $type = self::DEPENDENCY_REFERENCE_LOADED;
            } elseif (0 === strpos($value, '@?')) {
                $value = substr($value, 2);
                $type = self::DEPENDENCY_REFERENCE_OPTIONAL;
            } else {
                $value = substr($value, 1);
            }
        }
        return [$type, $value];
    }

    /**
     * Build arguments with dependencies (@see Container::fetchFunctionDependencies())
     * and parameters (@see Container::buildParameters())
     *
     * @param $dependencies
     * @param $parameters
     *
     * @return array
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function buildFunctionArguments($dependencies, $parameters)
    {
        $arguments = [];
        if ($dependencies) {
            foreach ($dependencies as $key => $dependency) {
                if (isset($parameters[$key])) {
                    $type = $parameters[$key]['type'];
                    $value = $parameters[$key]['value'];
                } elseif (isset($parameters[$dependency['name']])) {
                    $type = $parameters[$dependency['name']]['type'];
                    $value = $parameters[$dependency['name']]['value'];
                } else {
                    $type = $dependency['type'];
                    $value = $dependency['value'];
                }
                $arguments[] = $this->makeArgument($type, $value);
            }
        } else {
            foreach ($parameters as $key => $value) {
                $type = $parameters[$key]['type'];
                $value = $parameters[$key]['value'];
                $arguments[] = $this->makeArgument($type, $value);
            }
        }
        return $arguments;
    }

    /**
     * @param $type
     * @param $value
     *
     * @return mixed|null
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function makeArgument($type, $value)
    {
        switch ($type) {
            case self::DEPENDENCY_VALUE;
                return $value;

            case self::DEPENDENCY_REFERENCE_REQUIRED;
                if ($this->has($value)) {
                    return $this->get($value);
                }
                throw new NotFoundContainerException("There is no service with id {$value} found");

            case self::DEPENDENCY_REFERENCE_LOADED:
                if ($this->loaded($value)) {
                    return $this->get($value);
                }
                return null;

            case self::DEPENDENCY_REFERENCE_OPTIONAL:
                if ($this->has($value)) {
                    return $this->get($value);
                }
                return null;

            case self::DEPENDENCY_OBJECT_VALUE_REQUIRED:
                if ($this->hasReference($value)) {
                    return $this->getByReference($value);
                }
                throw new NotFoundContainerException("There is no referenced classes of {$value} found");

            case self::DEPENDENCY_OBJECT_VALUE_OPTIONAL:
                if ($this->hasReference($value)) {
                    return $this->getByReference($value);
                }
                return null;
        }

        return null;
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has($id)
    {
        return isset($this->_services[$id]) ||
            isset($this->_definitions[$id]) ||
            isset($this->_references[$id]);
    }

    /**
     * Check service is loaded
     *
     * @param $id
     * @return bool
     */
    public function loaded($id)
    {
        return isset($this->_services[$id]);
    }

    /**
     * Call method of service
     *
     * @param $id
     * @param $service
     * @param $method
     * @param array $attributes
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function call($id, $service, $method, array $attributes = [])
    {
        $parameters = $this->buildParameters($attributes);
        foreach ($parameters as $parameter) {
            if ($parameter['type'] == self::DEPENDENCY_REFERENCE_LOADED && !$this->loaded($parameter['value'])) {
                $this->addDelayedCall($parameter['value'], $id, $method, $attributes);
                return;
            }
        }
        $dependencies = $this->fetchCallableDependencies([$service, $method]);
        $arguments = $this->buildFunctionArguments($dependencies, $parameters);
        if ($arguments) {
            call_user_func_array([$service, $method], $arguments);
        } else {
            call_user_func([$service, $method]);
        }
    }

    /**
     * Add delayed call
     *
     * @param       $waitForService
     * @param       $callService
     * @param       $method
     * @param array $attributes
     */
    protected function addDelayedCall($waitForService, $callService, $method, array $attributes = [])
    {
        if (!isset($this->_delayedCalls[$waitForService])) {
            $this->_delayedCalls[$waitForService] = [];
        }
        $this->_delayedCalls[$waitForService][] = [
            'id' => $callService,
            'method' => $method,
            'attributes' => $attributes
        ];
    }

    /**
     * Read callable dependencies
     *
     * @param $callable
     * @return array
     * @throws ReflectionException
     */
    protected function fetchCallableDependencies($callable)
    {
        if (is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], $callable[1]);
        } elseif (is_object($callable) && !$callable instanceof \Closure) {
            $reflection = new \ReflectionMethod($callable, '__invoke');
        } else {
            $reflection = new \ReflectionFunction($callable);
        }
        return $this->fetchFunctionDependencies($reflection);
    }

    /**
     * Check delayed calls
     *
     * @param $id
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    protected function processDelayedCalls($id)
    {
        if (!$this->loaded($id)) {
            throw new ContainerException("Service {$id} not loaded, processing delayed calls is impossible");
        }
        if (isset($this->_delayedCalls[$id])) {
            foreach ($this->_delayedCalls[$id] as $delayedCall) {
                $this->call($delayedCall['id'], $this->_services[$delayedCall['id']], $delayedCall['method'], $delayedCall['attributes']);
            }
        }
    }

    /**
     * Invoke callable with dependency injection
     *
     * @param $callable
     * @param array $attributes
     * @return mixed
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     * @throws InvalidAttributeException
     */
    public function invoke($callable, array $attributes = [])
    {
        if (!is_callable($callable)) {
            throw new InvalidAttributeException('$callable attribute must be callable');
        }
        $dependencies = $this->fetchCallableDependencies($callable);
        $parameters = $this->buildParameters($attributes);
        $arguments = $this->buildFunctionArguments($dependencies, $parameters);
        if ($arguments) {
            return call_user_func_array($callable, $arguments);
        }

        return call_user_func($callable);
    }

    /**
     * Construct instance of class with constructor arguments
     *
     * @param $className
     * @param $arguments array
     * @return object
     * @throws ContainerException
     * @throws NotFoundContainerException
     * @throws ReflectionException
     */
    public function construct($className, $arguments = [])
    {
        return $this->make($className, $arguments);
    }
}