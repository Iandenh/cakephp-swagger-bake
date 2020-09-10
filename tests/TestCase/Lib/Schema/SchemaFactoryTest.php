<?php

namespace SwaggerBake\Test\TestCase\Lib\Schema;

use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use SwaggerBake\Lib\Configuration;
use SwaggerBake\Lib\Decorator\EntityDecorator;
use SwaggerBake\Lib\Decorator\PropertyDecorator;
use SwaggerBake\Lib\OpenApi\Schema;
use SwaggerBake\Lib\OpenApi\SchemaProperty;
use SwaggerBake\Lib\Schema\SchemaFactory;
use SwaggerBakeTest\App\Model\Entity\Department;

class SchemaFactoryTest extends TestCase
{
    /**
     * @var Configuration
     */
    private $configuration;

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->configuration = new Configuration([
            'prefix' => '/api',
            'yml' => '/config/swagger-bare-bones.yml',
            'json' => '/webroot/swagger.json',
            'webPath' => '/swagger.json',
            'hotReload' => false,
            'exceptionSchema' => 'Exception',
            'requestAccepts' => ['application/x-www-form-urlencoded'],
            'responseContentTypes' => ['application/json'],
            'namespaces' => [
                'controllers' => ['\SwaggerBakeTest\App\\'],
                'entities' => ['\SwaggerBakeTest\App\\'],
                'tables' => ['\SwaggerBakeTest\App\\'],
            ]
        ], SWAGGER_BAKE_TEST_APP);
    }

    public function testCreateSchema()
    {
        $entityDecorator = new EntityDecorator(new Department());
        $entityDecorator->setProperties([
            (new PropertyDecorator())->setName('id')->setType('integer')->setIsPrimaryKey(true),
            (new PropertyDecorator())->setName('name')->setType('string'),
            (new PropertyDecorator())->setName('created')->setType('datetime'),
            (new PropertyDecorator())->setName('modified')->setType('datetime'),
        ]);
        $schema = (new SchemaFactory($this->configuration))->create($entityDecorator);
        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testWriteSchema()
    {
        $entityDecorator = new EntityDecorator(new Department());
        $entityDecorator->setProperties([
            (new PropertyDecorator())->setName('id')->setType('integer')->setIsPrimaryKey(true),
            (new PropertyDecorator())->setName('name')->setType('string'),
            (new PropertyDecorator())->setName('created')->setType('datetime'),
            (new PropertyDecorator())->setName('modified')->setType('datetime'),
        ]);
        $schema = (new SchemaFactory($this->configuration))->create($entityDecorator, SchemaFactory::WRITEABLE_PROPERTIES);
        $this->assertCount(1, $schema->getProperties());
    }
}