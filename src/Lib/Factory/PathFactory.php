<?php

namespace SwaggerBake\Lib\Factory;

use Cake\Utility\Inflector;
use Exception;
use LogicException;
use ReflectionClass;
use phpDocumentor\Reflection\DocBlock;
use SwaggerBake\Lib\Annotation as SwagAnnotation;
use SwaggerBake\Lib\Configuration;
use SwaggerBake\Lib\Exception\SwaggerBakeRunTimeException;
use SwaggerBake\Lib\ExceptionHandler;
use SwaggerBake\Lib\Model\ExpressiveRoute;
use SwaggerBake\Lib\OpenApi\Content;
use SwaggerBake\Lib\OpenApi\OperationExternalDoc;
use SwaggerBake\Lib\OpenApi\Path;
use SwaggerBake\Lib\OpenApi\Parameter;
use SwaggerBake\Lib\OpenApi\RequestBody;
use SwaggerBake\Lib\OpenApi\Response;
use SwaggerBake\Lib\OpenApi\Schema;
use SwaggerBake\Lib\OpenApi\SchemaProperty;
use SwaggerBake\Lib\Utility\AnnotationUtility;
use SwaggerBake\Lib\Utility\DocBlockUtility;

/**
 * Class SwaggerPath
 */
class PathFactory
{
    private $route;
    private $prefix = '';
    private $config;

    public function __construct(ExpressiveRoute $route, Configuration $config)
    {
        $this->config = $config;
        $this->route = $route;
        $this->prefix = $config->getPrefix();
        $this->dockBlock = $this->getDocBlock();
    }

    /**
     * Creates a Path and returns it
     *
     * @return Path|null
     */
    public function create() : ?Path
    {
        $path = new Path();

        if (empty($this->route->getMethods())) {
            return null;
        }

        if (!$this->isControllerVisible($this->route->getController())) {
            return null;
        }

        foreach ($this->route->getMethods() as $method) {

            $methodAnnotations = $this->getMethodAnnotations(
                $this->route->getController(),
                $this->route->getAction()
            );

            if (!$this->isMethodVisible($methodAnnotations)) {
                continue;
            }

            $path
                ->setType(strtolower($method))
                ->setPath($this->getPathName())
                ->setOperationId($this->route->getName())
                ->setSummary($this->dockBlock ? $this->dockBlock->getSummary() : '')
                ->setDescription($this->dockBlock ? $this->dockBlock->getDescription() : '')
                ->setTags([
                    Inflector::humanize(Inflector::underscore($this->route->getController()))
                ])
                ->setParameters($this->getPathParameters())
                ->setDeprecated($this->isDeprecated())
            ;

            $path = $this->withDataTransferObject($path, $methodAnnotations);
            $path = $this->withResponses($path, $methodAnnotations);
            $path = $this->withRequestBody($path, $methodAnnotations);
            $path = $this->withExternalDoc($path);
        }

        return $path;
    }

    /**
     * Returns a route (e.g. /api/model/action)
     *
     * @return string
     */
    private function getPathName() : string
    {
        $pieces = array_map(
            function ($piece) {
                if (substr($piece, 0, 1) == ':') {
                    return '{' . str_replace(':', '', $piece) . '}';
                }
                return $piece;
            },
            explode('/', $this->route->getTemplate())
        );

        if ($this->prefix == '/') {
            return implode('/', $pieces);
        }

        return substr(
            implode('/', $pieces),
            strlen($this->prefix)
        );
    }

    /**
     * Returns an array of Parameter
     *
     * @return Parameter[]
     */
    private function getPathParameters() : array
    {
        $return = [];

        $pieces = explode('/', $this->route->getTemplate());
        $results = array_filter($pieces, function ($piece) {
           return substr($piece, 0, 1) == ':' ? true : null;
        });

        if (empty($results)) {
            return $return;
        }

        foreach ($results as $result) {

            $schema = new Schema();
            $schema
                ->setType('string')
            ;

            $name = strtolower($result);

            if (substr($name, 0, 1) == ':') {
                $name = substr($name, 1);
            }

            $parameter = new Parameter();
            $parameter
                ->setName($name)
                ->setAllowEmptyValue(false)
                ->setDeprecated(false)
                ->setRequired(true)
                ->setIn('path')
                ->setSchema($schema)
            ;
            $return[] = $parameter;
        }

        return $return;
    }

    /**
     * Returns an array of Lib/Annotation objects that can be applied to methods
     *
     * @param string $className
     * @param string $method
     * @return array
     */
    private function getMethodAnnotations(string $className, string $method) : array
    {
        $className = $className . 'Controller';
        $controller = $this->getControllerFromNamespaces($className);
        return AnnotationUtility::getMethodAnnotations($controller, $method);
    }

    private function withDataTransferObject(Path $path, array $annotations) : Path
    {
        if (empty($annotations)) {
            return $path;
        }

        $dataTransferObjects = array_filter($annotations, function ($annotation) {
            return $annotation instanceof SwagAnnotation\SwagDto;
        });

        if (empty($dataTransferObjects)) {
            return $path;
        }

        $dto = reset($dataTransferObjects);
        $class = $dto->class;

        if (!class_exists($class)) {
            return $path;
        }

        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $properties = DocBlockUtility::getProperties($instance);

        if (empty($properties)) {
            return $path;
        }

        $filteredProperties = array_filter($properties, function ($property) use ($instance) {
            if (!isset($property->class) || $property->class != get_class($instance)) {
                return null;
            }
            return true;
        });

        $pathType = strtolower($path->getType());
        if ($pathType == 'post') {
            $requestBody = new RequestBody();
            $schema = (new Schema())->setType('object');
        }

        foreach ($filteredProperties as $name => $reflectionProperty) {
            $docBlock = DocBlockUtility::getPropertyDocBlock($reflectionProperty);
            $vars = $docBlock->getTagsByName('var');
            if (empty($vars)) {
                throw new LogicException('@var must be set for ' . $class . '::' . $name);
            }
            $var = reset($vars);
            $dataType = DocBlockUtility::getDocBlockConvertedVar($var);

            if ($pathType == 'get') {
                $path->pushParameter(
                    (new Parameter())
                        ->setName($name)
                        ->setIn('query')
                        ->setRequired(!empty($docBlock->getTagsByName('required')))
                        ->setDescription($docBlock->getSummary())
                        ->setSchema((new Schema())->setType($dataType))
                );
            } else if ($pathType == 'post' && isset($schema)) {
                $schema->pushProperty(
                    (new SchemaProperty())
                        ->setDescription($docBlock->getSummary())
                        ->setName($name)
                        ->setType($dataType)
                        ->setRequired(!empty($docBlock->getTagsByName('required')))
                );
            }
        }

        if (isset($schema) && isset($requestBody)) {
            $content = (new Content())
                ->setMimeType('application/x-www-form-urlencoded')
                ->setSchema($schema);

            $path->setRequestBody(
                $requestBody->pushContent($content)
            );
        }

        return $path;
    }

    /**
     * Returns path with responses
     *
     * @param Path $path
     * @param array $annotations
     * @return Path
     */
    private function withResponses(Path $path, array $annotations) : Path
    {
        if (!empty($annotations)) {
            foreach ($annotations as $annotation) {
                if ($annotation instanceof SwagAnnotation\SwagResponseSchema) {
                    $path->pushResponse((new SwagAnnotation\SwagResponseSchemaHandler())->getResponse($annotation));
                }
            }
        }

        if (!$this->dockBlock || !$this->dockBlock->hasTag('throws')) {
            return $path;
        }

        $throws = $this->dockBlock->getTagsByName('throws');

        foreach ($throws as $throw) {
            $exception = new ExceptionHandler($throw->getType()->__toString());
            $path->pushResponse(
                (new Response())->setCode($exception->getCode())->setDescription($exception->getMessage())
            );
        }

        return $path;
    }

    /**
     * Returns Path with request body
     *
     * @param Path $path
     * @param array $annotations
     * @return Path
     */
    private function withRequestBody(Path $path, array $annotations) : Path
    {
        if (!empty($path->getRequestBody())) {
            return $path;
        }

        if (empty($annotations)) {
            return $path;
        }

        $requestBody = new RequestBody();

        foreach ($annotations as $annotation) {
            if ($annotation instanceof SwagAnnotation\SwagRequestBody) {
                $requestBody = (new SwagAnnotation\SwagRequestBodyHandler())->getResponse($annotation);
            }
        }

        foreach ($annotations as $annotation) {
            if ($annotation instanceof SwagAnnotation\SwagRequestBodyContent) {
                $requestBody->pushContent(
                    (new SwagAnnotation\SwagRequestBodyContentHandler())->getContent($annotation)
                );
            }
        }

        if (empty($requestBody->getContent())) {
            return $path->setRequestBody($requestBody);
        }

        return $path->setRequestBody($requestBody);
    }

    /**
     * @return DocBlock|null
     */
    private function getDocBlock() : ?DocBlock
    {
        if (empty($this->route->getController())) {
            return null;
        }

        $className = $this->route->getController() . 'Controller';
        $methodName = $this->route->getAction();
        $controller = $this->getControllerFromNamespaces($className);

        if (!class_exists($controller)) {
            return null;
        }

        try {
            return DocBlockUtility::getMethodDocBlock(new $controller, $methodName);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param string $className
     * @return string|null
     */
    private function getControllerFromNamespaces(string $className) : ?string
    {
        $namespaces = $this->config->getNamespaces();

        if (!isset($namespaces['controllers']) || !is_array($namespaces['controllers'])) {
            throw new SwaggerBakeRunTimeException(
                'Invalid configuration, missing SwaggerBake.namespaces.controllers'
            );
        }

        foreach ($namespaces['controllers'] as $namespace) {
            $entity = $namespace . 'Controller\\' . $className;
            if (class_exists($entity, true)) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @param string $className
     * @return bool
     */
    private function isControllerVisible(string $className) : bool
    {
        $className = $className . 'Controller';
        $controller = $this->getControllerFromNamespaces($className);

        if (!$controller) {
            return false;
        }

        $annotations = AnnotationUtility::getClassAnnotations($controller);

        foreach ($annotations as $annotation) {
            if ($annotation instanceof SwagAnnotation\SwagPath) {
                return $annotation->isVisible;
            }
        }

        return true;
    }

    /**
     * @param array $annotations
     * @return bool
     */
    private function isMethodVisible(array $annotations) : bool
    {
        foreach ($annotations as $annotation) {
            if ($annotation instanceof SwagAnnotation\SwagOperation) {
                return $annotation->isVisible;
            }
        }

        return true;
    }

    /**
     * Check if this path/operation is deprecated
     *
     * @return bool
     */
    private function isDeprecated() : bool
    {
        if (!$this->dockBlock || !$this->dockBlock instanceof DocBlock) {
            return false;
        }

        return $this->dockBlock->hasTag('deprecated');
    }

    /**
     * Defines external documentation using see tag
     * @param Path $path
     * @return Path
     */
    private function withExternalDoc(Path $path) : Path
    {
        if (!$this->dockBlock || !$this->dockBlock instanceof DocBlock) {
            return $path;
        }

        if (!$this->dockBlock->hasTag('see')) {
            return $path;
        }

        $tags = $this->dockBlock->getTagsByName('see');
        $seeTag = reset($tags);
        $str = $seeTag->__toString();
        $pieces = explode(' ', $str);

        if (!filter_var($pieces[0], FILTER_VALIDATE_URL)) {
            return $path;
        }

        $externalDoc = new OperationExternalDoc();
        $externalDoc->setUrl($pieces[0]);

        array_shift($pieces);

        if (!empty($pieces)) {
            $externalDoc->setDescription(implode(' ', $pieces));
        }

        return $path->setExternalDocs($externalDoc);
    }
}
