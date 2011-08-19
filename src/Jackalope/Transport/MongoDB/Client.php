<?php

/**
 * Class to handle the communication between Jackalope and MongoDB.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0', January 2004
 *   Licensed under the Apache License, Version 2.0 (the "License") {}
 *   you may not use this file except in compliance with the License.
 *   You may obtain a copy of the License at
 *
 *       http://www.apache.org/licenses/LICENSE-2.0
 *
 *   Unless required by applicable law or agreed to in writing, software
 *   distributed under the License is distributed on an "AS IS" BASIS,
 *   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *   See the License for the specific language governing permissions and
 *   limitations under the License.
 *
 * @package jackalope
 * @subpackage transport
 */

namespace Jackalope\Transport\MongoDB;

use PHPCR\PropertyType;
use PHPCR\RepositoryException;
use PHPCR\Util\UUIDHelper;
use Jackalope\TransportInterface;
use Jackalope\Helper;
use Jackalope\Transport\Client as ClientAbstract;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\NodeType\PHPCR2StandardNodeTypes;
use Doctrine\MongoDb\Connection;
use Doctrine\MongoDb\Database;

/**
 * @author Thomas Schedler <thomas@chirimoya.at>
 */
class Client extends ClientAbstract implements TransportInterface
{
    
    /**
     * Name of MongoDB workspace collection.
     * 
     * @var string
     */
    const COLLNAME_WORKSPACES = 'phpcr_workspaces';
    
    /**
     * Name of MongoDB namespace collection.
     * 
     * @var string
     */
    const COLLNAME_NAMESPACES = 'phpcr_namespaces';
    
    /**
     * Name of MongoDB node collection.
     * 
     * @var string
     */
    const COLLNAME_NODES = 'phpcr_nodes';
    
    /**
     * @var Doctrine\MongoDB\Database
     */
    private $db;
    
    /**
     * Create a transport pointing to a server url.
     *
     * @param object $factory  an object factory implementing "get" as described in \Jackalope\Factory.
     * @param Doctrine\MongoDB\Database $db
     */
    public function __construct($factory, Database $db)
    {
        $this->factory = $factory;
        $this->db = $db;
    }

    /**
     * Creates a new Workspace with the specified name. The new workspace is
     * empty, meaning it contains only root node.
     *
     * If srcWorkspace is given:
     * Creates a new Workspace with the specified name initialized with a
     * clone of the content of the workspace srcWorkspace. Semantically,
     * this method is equivalent to creating a new workspace and manually
     * cloning srcWorkspace to it; however, this method may assist some
     * implementations in optimizing subsequent Node.update and Node.merge
     * calls between the new workspace and its source.
     *
     * The new workspace can be accessed through a login specifying its name.
     *
     * @param string $name A String, the name of the new workspace.
     * @param string $srcWorkspace The name of the workspace from which the new workspace is to be cloned.
     * @return void
     * @throws \PHPCR\AccessDeniedException if the session through which this Workspace object was acquired does not have sufficient access to create the new workspace.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if the repository does not support the creation of workspaces.
     * @throws \PHPCR\NoSuchWorkspaceException if $srcWorkspace does not exist.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function createWorkspace($workspaceName, $srcWorkspace = null)
    {
        if ($srcWorkspace !== null) {
            throw new \Jackalope\NotImplementedException();
        }
        
        $workspaceId = $this->getWorkspaceId($workspaceName);
        if ($workspaceId !== false) {
            throw new \PHPCR\RepositoryException("Workspace '" . $workspaceName . "' already exists");
        }
        
        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        $workspace = array(
            'name' => $workspaceName
        );
        $coll->insert($workspace);
        
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $rootNode = array(
            '_id' => new \MongoBinData(UUIDHelper::generateUUID(), \MongoBinData::UUID),
            'path' => '/',
            'parent' => '-1',
            'w_id' => $workspace['_id'],
            'type' => 'nt:unstructured',
            'props' => array()
        );
        $coll->insert($rootNode);
    }

    /**
     * Set this transport to a specific credential and a workspace.
     *
     * This can only be called once. To connect to another workspace or with
     * another credential, use a fresh instance of transport.
     *
     * @param credentials A \PHPCR\SimpleCredentials instance (this is the only type currently understood)
     * @param workspaceName The workspace name for this transport.
     * @return true on success (exceptions on failure)
     *
     * @throws \PHPCR\LoginException if authentication or authorization (for the specified workspace) fails
     * @throws \PHPCR\NoSuchWorkspacexception if the specified workspaceName is not recognized
     * @throws \PHPCR\RepositoryException if another error occurs
     */
    public function login(\PHPCR\CredentialsInterface $credentials, $workspaceName)
    {
        $this->workspaceId = $this->getWorkspaceId($workspaceName);
        if (!$this->workspaceId) {
            throw new \PHPCR\NoSuchWorkspaceException;
        }

        $this->loggedIn = true;
        return true;
    }
    
    /**
     * Releases all resources associated with this Session.
     *
     * This method should be called when a Session is no longer needed.
     *
     * @return void
     */
    public function logout()
    {
        $this->loggedIn = false;
    }

    /**
     * Get workspace Id.
     * 
     * @param string $workspaceName
     * @return string|bool
     */
    private function getWorkspaceId($workspaceName)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        
        $qb = $coll->createQueryBuilder()
                   ->field('name')->equals($workspaceName);
        
        $query = $qb->getQuery();
        $workspace = $query->getSingleResult();

        return ($workspace != null) ? $workspace['_id'] : false;
    }

    /**
     * Get the registered namespaces mappings from the backend.
     *
     * @return array Associative array of prefix => uri
     * 
     * @throws \PHPCR\RepositoryException if now logged in
     */
    public function getNamespaces()
    {
        if ($this->userNamespaces === null) {
            $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
            
            $namespaces = $coll->find();
            $this->userNamespaces = array();
            
            foreach ($namespaces AS $namespace) {
                $this->validNamespacePrefixes[$namespace['prefix']] = true;
                $this->userNamespaces[$namespace['prefix']] = $namespace['uri'];
            }
        }
        return $this->userNamespaces;
    }

    /**
     * Copies a Node from src to dst
     *
     * @param   string  $srcAbsPath     Absolute source path to the node
     * @param   string  $dstAbsPath     Absolute destination path (must include the new node name)
     * @param   string  $srcWorkspace   The source workspace where the node can be found or NULL for current
     * @return void
     * 
     * @throws \PHPCR\NoSuchWorkspaceException if source workspace doesn't exist
     * @throws \PHPCR\RepositoryException if destination path is invalid
     * @throws \PHPCR\PathNotFoundException if source path is not found
     * @throws \PHPCR\ItemExistsException if destination path already exists
     *
     * @link http://www.ietf.org/rfc/rfc2518.txt
     * @see \Jackalope\Workspace::copy
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        $this->assertLoggedIn();
        
        $srcAbsPath = $this->validatePath($srcAbsPath);
        $dstAbsPath = $this->validatePath($dstAbsPath);

        $workspaceId = $this->workspaceId;
        if (null !== $srcWorkspace) {
            $workspaceId = $this->getWorkspaceId($srcWorkspace);
            if ($workspaceId === false) {
                throw new \PHPCR\NoSuchWorkspaceException("Source workspace '" . $srcWorkspace . "' does not exist.");
            }
        }

        if (substr($dstAbsPath, -1, 1) == "]") {
            // TODO: Understand assumptions of CopyMethodsTest::testCopyInvalidDstPath more
            throw new \PHPCR\RepositoryException("Invalid destination path");
        }

        if (!$this->pathExists($srcAbsPath)) {
            throw new \PHPCR\PathNotFoundException("Source path '".$srcAbsPath."' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new \PHPCR\ItemExistsException("Cannot copy to destination path '" . $dstAbsPath . "' that already exists.");
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new \PHPCR\PathNotFoundException("Parent of the destination path '" . $this->getParentPath($dstAbsPath) . "' has to exist.");
        }
        
        try {

            $regex = new \MongoRegex('/^' . addcslashes($srcAbsPath, '/') . '/'); 
            
            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                       ->field('path')->equals($regex)
                       ->field('w_id')->equals($workspaceId);
    
            $query = $qb->getQuery();
            $nodes = $query->getIterator();
            
            foreach ($nodes as $node){
                $newPath = str_replace($srcAbsPath, $dstAbsPath, $node['path']);
                $uuid = UUIDHelper::generateUUID();
                
                $node['_id'] = new \MongoBinData($uuid, \MongoBinData::UUID);
                $node['path'] = $newPath;
                $node['parent'] = $this->getParentPath($newPath);
                $node['w_id'] = $this->workspaceId;
                
                $coll->insert($node);
            }
            
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Returns the accessible workspace names
     *
     * @return array Set of workspaces to work on.
     */
    public function getAccessibleWorkspaceNames()
    {
        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        
        $workspaces = $coll->find();
        
        $workspaceNames = array();
        foreach ($workspaces AS $workspace) {
            $workspaceNames[] = $workspace['name'];
        }
        return $workspaceNames;
    }

    /**
     * Get the node from an absolute path
     *
     * Returns a json_decode stdClass structure that contains two fields for
     * each property and one field for each child.
     * A child is just containing an empty class as value (in the future we
     * could use this for eager loading with recursive structure).
     * A property consists of a field named as the property is and a value that
     * is the property value, plus a second field with the same name but
     * prefixed with a colon that has a type specified as value (out of the
     * string constants from PropertyType)
     *
     * For binary properties, the value of the type declaration is not the type
     * but the length of the binary, thus integer instead of string.
     * There is no value field for binary data (to avoid loading large amount
     * of unneeded data)
     * Use getBinaryStream to get the actual data of a binary property.
     *
     * There is a couple of "magic" properties:
     * <ul>
     *   <li>jcr:uuid - the unique id of the node</li>
     *   <li>jcr:primaryType - name of the primary type</li>
     *   <li>jcr:mixinTypes - comma separated list of mixin types</li>
     *   <li>jcr:index - the index of same name siblings</li>
     * </ul>
     *
     * @example Return struct
     * <code>
     * object(stdClass)#244 (4) {
     *      ["jcr:uuid"]=>
     *          string(36) "64605997-e298-4334-a03e-673fc1de0911"
     *      [":jcr:primaryType"]=>
     *          string(4) "Name"
     *      ["jcr:primaryType"]=>
     *          string(8) "nt:unstructured"
     *      ["myProperty"]=>
     *          string(4) "test"
     *      [":myProperty"]=>
     *          string(5) "String" //one of \PHPCR\PropertyTypeInterface::TYPENAME_NAME
     *      [":myBinary"]=>
     *          int 1538    //length of binary file, no "myBinary" field present
     *      ["childNodeName"]=>
     *          object(stdClass)#152 (0) {}
     *      ["otherChild"]=>
     *          object(stdClass)#153 (0) {}
     * }
     * </code>
     *
     * Note: the reason to use json_decode with associative = false is that the
     * array version can not distinguish between
     *   ['foo', 'bar'] and {0: 'foo', 1: 'bar'}
     * The first are properties, but the later is a list of children nodes.
     *
     * @param string $path Absolute path to the node.
     * @return array associative array for the node (decoded from json with associative = true)
     *
     * @throws \PHPCR\ItemNotFoundException If the item at path was not found
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNode($path)
    {
        $this->assertLoggedIn();
        $path = $this->validatePath($path);
        
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll->createQueryBuilder()
                   ->field('path')->equals($path)
                   ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();
        $node = $query->getSingleResult();
        
        if (!$node) {
            throw new \PHPCR\ItemNotFoundException("Item ".$path." not found.");
        }

        $data = new \stdClass();
        
        if($node['_id'] instanceof \MongoBinData) {
            $data->{'jcr:uuid'} = $node['_id']->bin;
        }
        $data->{'jcr:primaryType'} = $node['type'];
        
        foreach ($node['props'] as $prop) {
            $name = $prop['name'];
            $type = $prop['type'];
            
            if ($type == \PHPCR\PropertyType::TYPENAME_BINARY) {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $data->{":" . $name}[] = $value;
                    }
                } else {
                    $data->{":" . $name} = $prop['value'];
                }
            } else if ($type == \PHPCR\PropertyType::TYPENAME_DATE) {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $date = new \DateTime(date('Y-m-d H:i:s', $value['date']->sec), new \DateTimeZone($value['timezone']));
                        $data->{$name}[] = $date->format('c');
                    }
                } else {
                    $date = new \DateTime(date('Y-m-d H:i:s', $prop['value']['date']->sec), new \DateTimeZone($prop['value']['timezone']));
                    $data->{$name} = $date->format('c');
                }
                
                $data->{":" . $name} = $type;
            } else {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $data->{$name}[] = $value;    
                    }
                } else {
                    $data->{$name} = $prop['value'];
                }
                $data->{":" . $name} = $type;
            }
        }
        
        $qb = $coll->createQueryBuilder()
                   ->field('parent')->equals($path)
                   ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();
        $children = $query->getIterator();
        
        foreach ($children AS $child) {
            $childName = explode("/", $child['path']);
            $childName = end($childName);
            $data->{$childName} = new \stdClass();
        }

        return $data;
    }
    
    /**
     * Check-in item at path.
     *
     * @param string $path
     * @return string
     *
     * @throws PHPCR\UnsupportedRepositoryOperationException
     * @throws PHPCR\RepositoryException
     */
    public function checkinItem($path)
    {
        throw new \Jackalope\NotImplementedException();
    }

    /**
     * Check-out item at path.
     *
     * @param string $path
     * @return void
     *
     * @throws PHPCR\UnsupportedRepositoryOperationException
     * @throws PHPCR\RepositoryException
     */
    public function checkoutItem($path)
    {
        throw new \Jackalope\NotImplementedException();
    }

    public function restoreItem($removeExisting, $versionPath, $path)
    {
        throw new \Jackalope\NotImplementedException();
    }

    public function getVersionHistory($path)
    {
        throw new \Jackalope\NotImplementedException();
    }

    public function querySQL($query, $limit = null, $offset = null)
    {
        throw new \Jackalope\NotImplementedException();
    }

    /**
     * Checks if path exists.
     * 
     * @param string $path
     * @return bool
     */
    private function pathExists($path)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        
        $qb = $coll->createQueryBuilder()
                   ->field('path')->equals($path)
                   ->field('w_id')->equals($this->workspaceId);
        
        $query = $qb->getQuery();
        
        if (!$query->getSingleResult()) {
            return false;
        }
        return true;
    }

    /**
     * Deletes a node and its subnodes
     *
     * @param string $path Absolute path to identify a special item.
     * @return bool true on success
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function deleteNode($path)
    {
        $path = $this->validatePath($path);
        $this->assertLoggedIn();

        if (!$this->pathExists($path)) {
            $this->deleteProperty($path);
        } else {
        
            //TODO check subnode references!
            if(count($this->getReferences($path)) > 0){
                throw new \PHPCR\ReferentialIntegrityException(
                    "Cannot delete item at path '".$path."', there is at least one item with ".
                    "a reference to this or a subnode of the path."
                ); 
                return false;
            }
            
            try {
                
                $regex = new \MongoRegex('/^' . addcslashes($path, '/') . '/'); 
                
                $coll = $this->db->selectCollection(self::COLLNAME_NODES);
                $qb = $coll->createQueryBuilder()
                           ->remove()
                           ->field('path')->equals($regex)
                           ->field('w_id')->equals($this->workspaceId);
                $query = $qb->getQuery();
            
                return $query->execute();
            } catch(\Exception $e) {
                return false;
            }
        }
    }

    /**
     * Deletes a property
     *
     * @param string $path Absolute path to identify a special item.
     * @return bool true on success
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function deleteProperty($path)
    {
        $this->assertLoggedIn();
        
        $path = $this->validatePath($path);
        $parentPath = $this->getParentPath($path);
        
        $name = trim(str_replace($parentPath, '', $path), '/');
        
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        
        $qb = $coll->createQueryBuilder()
                   ->select('_id')
                   ->field('props.name')->equals($name)
                   ->field('path')->equals($parentPath)
                   ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();
    
        $property = $query->getSingleResult();
        
        if (!$property) {
            throw new \PHPCR\ItemNotFoundException("Property ".$path." not found.");
        }
        
        $qb = $coll->createQueryBuilder()
                   ->update()
                   ->field('props')->pull(array('name' => $name))
                   ->field('path')->equals($parentPath)
                   ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();
        
        return $query->execute();
    }

    /**
     * Moves a node from src to dst
     *
     * @param   string  $srcAbsPath     Absolute source path to the node
     * @param   string  $dstAbsPath     Absolute destination path (must NOT include the new node name)
     * @return void
     *
     * @link http://www.ietf.org/rfc/rfc2518.txt
     * @see \Jackalope\Workspace::moveNode
     */
    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        $this->assertLoggedIn();
        
        $srcAbsPath = $this->validatePath($srcAbsPath);
        $dstAbsPath = $this->validatePath($dstAbsPath);

        if (!$this->pathExists($srcAbsPath)) {
            throw new \PHPCR\PathNotFoundException("Source path '".$srcAbsPath."' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new \PHPCR\ItemExistsException("Cannot copy to destination path '" . $dstAbsPath . "' that already exists.");
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new \PHPCR\PathNotFoundException("Parent of the destination path '" . $this->getParentPath($dstAbsPath) . "' has to exist.");
        }
        
        try {

            $regex = new \MongoRegex('/^' . addcslashes($srcAbsPath, '/') . '/'); 
            
            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                       ->field('path')->equals($regex)
                       ->field('w_id')->equals($this->workspaceId);
    
            $query = $qb->getQuery();
            $nodes = $query->getIterator();
            
            foreach ($nodes as $node){
                $newPath = str_replace($srcAbsPath, $dstAbsPath, $node['path']);
                
                $node['path'] = $newPath;
                $node['parent'] = $this->getParentPath($newPath);
                
                $coll->save($node);
            }
            
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Recursively store a node and its children to the given absolute path.
     *
     * Transport stores the node at its path, with all properties and all children
     *
     * @param \PHPCR\NodeInterface $node the node to store
     * @return bool true on success
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function storeNode(\PHPCR\NodeInterface $node)
    {
        $this->assertLoggedIn();
        
        $path = $node->getPath();
        $path = $this->validatePath($path);
        
        // getting the property definitions is a copy of the DoctrineDBAL 
        // implementation - maybe there is a better way?
        
        // This is very slow i believe :-(
        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);
        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            foreach ($nodeType->getDeclaredSupertypes() AS $superType) {
                $nodeTypes[] = $superType;
            }
        }

        $popertyDefs = array();
        foreach ($nodeTypes AS $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            foreach ($nodeType->getDeclaredPropertyDefinitions() AS $itemDef) {
                /* @var $itemDef \PHPCR\NodeType\ItemDefinitionInterface */
                if ($itemDef->getName() == '*') {
                    continue;
                }
                
                if (isset($popertyDefs[$itemDef->getName()])) {
                    throw new \PHPCR\RepositoryException("DoctrineTransport does not support child/property definitions for the same subpath.");
                }
                $popertyDefs[$itemDef->getName()] = $itemDef;
            }
            $this->validateNode($node, $nodeType);
        }

        $properties = $node->getProperties();

        try {
            $nodeIdentifier = (isset($properties['jcr:uuid'])) ? $properties['jcr:uuid']->getNativeValue() : UUIDHelper::generateUUID();
            
            $props = array();
            foreach ($properties AS $property) {
                $data = $this->decodeProperty($property, $popertyDefs);
                if (!empty($data)) {
                    $props[] = $data;  
                }
            }
            
            $data = array(
                '_id' => new \MongoBinData($nodeIdentifier, \MongoBinData::UUID),
                'path' => $path,
                'parent' => $this->getParentPath($path),
                'w_id'  => $this->workspaceId,
                'type' => isset($properties['jcr:primaryType']) ? $properties['jcr:primaryType']->getValue() : 'nt:unstructured',
                'props' => $props
            );
            
            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            if (!$this->pathExists($path)) {
                $coll->insert($data);
            }else{
                $qb = $coll->createQueryBuilder()
                           ->update()
                           ->setNewObj($data)
                           ->field('path')->equals($path)
                           ->field('w_id')->equals($this->workspaceId);
                $query = $qb->getQuery();
                $query->execute();  //FIXME use _id for update?
            }
            
            if ($node->hasNodes()) {
                foreach($node->getNodes() as $childNode) {
                    $this->storeNode($childNode);
                }
            }            
            
        } catch(\Exception $e) {
            throw new \PHPCR\RepositoryException("Storing node " . $node->getPath() . " failed: " . $e->getMessage(), null, $e);
        }

        return true;
    }

    /**
     * Stores a property to the given absolute path
     *
     * @param string $path Absolute path to identify a specific property.
     * @param \PHPCR\PropertyInterface
     * @return bool true on success
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function storeProperty(\PHPCR\PropertyInterface $property)
    {   
        $this->assertLoggedIn();
        
        $path = $property->getPath();
        $path = $this->validatePath($path);
        
        $parent = $property->getParent();
        $parentPath = $this->validatePath($parent->getPath());
        
        try {
            $data = $this->decodeProperty($property);
        
            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                       ->select('_id')
                       ->findAndUpdate()
                       ->field('props.name')->equals($property->getName())
                       ->field('path')->equals($parentPath)
                       ->field('w_id')->equals($this->workspaceId)
                       ->field('props.$')->set($data);
            $query = $qb->getQuery();
            
            $node = $query->execute();
            
            if (empty($node)) {
                $qb = $coll->createQueryBuilder()
                       ->update()
                       ->field('path')->equals($parentPath)
                       ->field('w_id')->equals($this->workspaceId)
                       ->field('props')->push($data);
                $query = $qb->getQuery();  
                $query->execute();
            }
            
        } catch(\Exception $e) {
            throw $e;
        }
        
        return true;
    }
    
    /**
     * "Decode" PHPCR property to MongoDB property
     * 
     * @param $property
     * @param $propDefinitions
     * @return array|null
     */
    private function decodeProperty(\PHPCR\PropertyInterface $property, $propDefinitions = array())
    {
        $path = $property->getPath();
        $path = $this->validatePath($path);
        $name = explode("/", $path);
        $name = end($name);
        
        if ($name == "jcr:uuid" || $name == "jcr:primaryType") {
            return null;
        }

        if (!$property->isModified() && !$property->isNew()) {
            return null;
        }
        
        if (($property->getType() == PropertyType::REFERENCE || $property->getType() == PropertyType::WEAKREFERENCE) &&
            !$property->getNode()->isNodeType('mix:referenceable')) {
            throw new \PHPCR\ValueFormatException('Node ' . $property->getNode()->getPath() . ' is not referencable');
        }
         
        $isMultiple = $property->isMultiple();
        if (isset($propDefinitions[$name])) {
            /* @var $propertyDef \PHPCR\NodeType\PropertyDefinitionInterface */
            $propertyDef = $propDefinitions[$name];
            if ($propertyDef->isMultiple() && !$isMultiple) {
                $isMultiple = true;
            } else if (!$propertyDef->isMultiple() && $isMultiple) {
                throw new \PHPCR\ValueFormatException(
                    'Cannot store property ' . $property->getPath() . ' as array, '.
                    'property definition of nodetype ' . $propertyDef->getDeclaringNodeType()->getName() .
                    ' requests a single value.'
                );
            }

            if ($propertyDef !== \PHPCR\PropertyType::UNDEFINED) {
                // TODO: Is this the correct way? No side effects while initializtion?
                $property->setValue($property->getValue(), $propertyDef->getRequiredType());
            }

            foreach ($propertyDef->getValueConstraints() AS $valueConstraint) {
                // TODO: Validate constraints
            }
        }
        
        $typeId = $property->getType();
        $type = PropertyType::nameFromValue($typeId);
        
        $data = array(
            'multi' => $isMultiple,
            'name'  => $property->getName(),
            'type'  => $type,
        );

        $binaryData = null;
        switch ($typeId) {
            case \PHPCR\PropertyType::NAME:
            case \PHPCR\PropertyType::URI:
            case \PHPCR\PropertyType::WEAKREFERENCE:
            case \PHPCR\PropertyType::REFERENCE:
            case \PHPCR\PropertyType::PATH:
                $values = $property->getString();
                break;
            case \PHPCR\PropertyType::DECIMAL:
                $values = $property->getDecimal();
                break;
            case \PHPCR\PropertyType::STRING:
                $values = $property->getString();
                break;
            case \PHPCR\PropertyType::BOOLEAN:
                $values = $property->getBoolean();
                break;
            case \PHPCR\PropertyType::LONG:
                $values = $property->getLong();
                break;
            case \PHPCR\PropertyType::BINARY:
                if ($property->isMultiple()) {
                    foreach ((array)$property->getBinary() AS $binary) {
                        $binary = stream_get_contents($binary);
                        $binaryData[] = $binary;
                        $values[] = strlen($binary);
                    }
                } else {
                    $binary = stream_get_contents($property->getBinary());
                    $binaryData[] = $binary;
                    $values = strlen($binary);
                }
                break;
            case \PHPCR\PropertyType::DATE:
                if ($property->isMultiple()) {
                    $dates = $property->getDate() ?: new \DateTime('now');
                    foreach ((array)$dates AS $date) {
                        $value = array(
                            'date' => new \MongoDate($date->getTimestamp()), 
                            'timezone' => $date->getTimezone()->getName()
                        );
                        $values[] = $value;
                    }
                } else {
                    $date = $property->getDate() ?: new \DateTime('now');
                    $values = array(
                        'date' => new \MongoDate($date->getTimestamp()), 
                        'timezone' => $date->getTimezone()->getName()
                    );
                }
                break;
            case \PHPCR\PropertyType::DOUBLE:
                $values = $property->getDouble();
                break;
        }

        if ($isMultiple) {
            $data['value'] = array();
            foreach ((array)$values AS $value) {
                $this->assertValidPropertyValue($data['type'], $value, $path);

                $data['value'][] = $value;
            }
        } else {
            $this->assertValidPropertyValue($data['type'], $values, $path);

            $data['value'] = $values;
        }
        
        
        if ($binaryData) {
            try {    
                foreach ($binaryData AS $idx => $binary) {
                    $grid = $this->db->getGridFS();
                    $grid->getMongoCollection()->storeBytes($binary, array(
                        'path' => $path,
                        'w_id' => $this->workspaceId,
                        'idx'  => $idx
                    ));
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
        
        return $data;
    }

    /**
     * Get the node path from a JCR uuid
     *
     * @param string $uuid the id in JCR format
     * @return string Absolute path to the node
     *
     * @throws \PHPCR\ItemNotFoundException if the backend does not know the uuid
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNodePathForIdentifier($uuid)
    {
        $this->assertLoggedIn();
        
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        
        $qb = $coll->createQueryBuilder()
                   ->field('_id')->equals(new \MongoBinData($uuid, \MongoBinData::UUID))
                   ->field('w_id')->equals($this->workspaceId);
        
        $query = $qb->getQuery();
        $node = $query->getSingleResult();
        
        if (empty($node)) {
            throw new \PHPCR\ItemNotFoundException("no item found with uuid ".$uuid);
        }
        return $node['path'];
    }

    /**
     * Register namespaces and new node types or update node types based on a
     * jackrabbit cnd string
     *
     * @see \Jackalope\NodeTypeManager::registerNodeTypesCnd
     *
     * @param $cnd The cnd string
     * @param boolean $allowUpdate whether to fail if node already exists or to update it
     * @return bool true on success
     */
    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        throw new \Jackalope\NotImplementedException("Not implemented yet");
    }

    /**
     * @param array $types a list of \PHPCR\NodeType\NodeTypeDefinitionInterface objects
     * @param boolean $allowUpdate whether to fail if node already exists or to update it
     * @return bool true on success
     */
    public function registerNodeTypes($types, $allowUpdate)
    {
        throw new \Jackalope\NotImplementedException("Not implemented yet");
    }

    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new \Jackalope\NotImplementedException("Not implemented yet");
    }

    /**
     * Retrieve a stream of a binary property value
     *
     * @param $path The path to the property with the binary data
     * @return resource with binary data
     */
    public function getBinaryStream($path)
    {   
        $grid = $this->db->getGridFS();
        $binary = $grid->getMongoCollection()->findOne(array(
            'path' => $path,
            'w_id' => $this->workspaceId
        ));
        
        if (empty($binary)) {
            throw new \PHPCR\ItemNotFoundException("Binary ".$path." not found.");
        }
        
        // TODO: OPTIMIZE stream handling!
        $stream = fopen('php://memory', 'rwb+');
        fwrite($stream, $binary->getBytes());
        rewind($stream);
        return $stream;
    }

    public function getProperty($path)
    {
        throw new \Jackalope\NotImplementedException("Not implemented yet");
    }

    /**
     * Search something with the backend.
     *
     * The language must be among those returned by getSupportedQueryLanguages
     *
     * Implementors: Expose all information required by the transport layers to
     * execute the query with getters.
     *
     * array(
     *     //row 1
     *     array(
     *         //column1
     *         array('dcr:name' => 'value1',
     *               'dcr:value' => 'value2',
     *               'dcr:selectorName' => 'value3' //optional
     *         ),
     *         //column 2...
     *     ),
     *     //row 2
     *     array(...
     * )
     *
     * @param \PHPCR\Query\QueryInterface $query the query object
     * @return array data with search result. TODO: what to return? should be some simple array
     * @see Query\QueryResult::__construct for the xml format. TODO: have the transport return a QueryResult?
     */
    public function query(\PHPCR\Query\QueryInterface $query)
    {
        $limit = $query->getLimit();
        $offset = $query->getOffset();

        switch ($query->getLanguage()) {
            case \PHPCR\Query\QueryInterface::JCR_SQL2:
                $parser = new \PHPCR\Util\QOM\Sql2ToQomQueryConverter(new \Jackalope\Query\QOM\QueryObjectModelFactory());
                $qom = $parser->parse($query->getStatement());
               
                $coll = $this->db->selectCollection(self::COLLNAME_NODES);
                $qb = $coll->createQueryBuilder();
                           
                $qomWalker = new Query\QOMWalker($this->nodeTypeManager, $qb, $this->getNamespaces());
                $qomWalker->walkQOMQuery($qom);
                
                $nodes = $qb->field('w_id')->equals($this->workspaceId)
                            ->limit($limit)
                            ->skip($offset)
                            ->getQuery()
                            ->getIterator();
                            
                $result = array();
                
                foreach ($nodes AS $node) {
                    
                    var_dump($node);
                
                    $result[] = array(
                        array('dcr:name' => 'jcr:primaryType', 'dcr:value' => $node['type']),
                        array('dcr:name' => 'jcr:path', 'dcr:value' => $node['path'], 'dcr:selectorName' => $node['type']),
                        array('dcr:name' => 'jcr:score', 'dcr:value' => 0)
                    );
                }

                return $result;
            case \PHPCR\Query\QueryInterface::JCR_JQOM:
                // How do we extrct the QOM from a QueryInterface? We need a non-interface method probably
                throw new \Jackalope\NotImplementedException("JCQ-JQOM not yet implemented.");
                break;
        }
    }

    /**
     * Register a new namespace.
     *
     * Validation based on what was returned from getNamespaces has already
     * happened in the NamespaceRegistry.
     *
     * The transport is however responsible of removing an existing prefix for
     * that uri, if one exists. As well as removing the current uri mapped to
     * this prefix if this prefix is already existing.
     *
     * @param string $prefix The prefix to be mapped.
     * @param string $uri The URI to be mapped.
     */
    public function registerNamespace($prefix, $uri)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
        $namespace = array(
            'prefix' => $prefix,
            'uri' => $uri,
        );
        $coll->insert($namespace);
    }

    /**
     * Unregister an existing namespace.
     *
     * Validation based on what was returned from getNamespaces has already
     * happened in the NamespaceRegistry.
     *
     * @param string $prefix The prefix to unregister.
     */
    public function unregisterNamespace($prefix)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
        $qb = $coll->createQueryBuilder()
                   ->field('prefix')->equals($prefix);
        
        $query = $qb->getQuery();
        $coll->remove($query);
    }
    
    /**
     * Returns node types
     * @param array nodetypes to request
     * @return dom with the definitions
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $nodeTypes = array_flip($nodeTypes);

        $data = PHPCR2StandardNodeTypes::getNodeTypeData();
        $filteredData = array();
        foreach ($data AS $nodeTypeData) {
            if (isset($nodeTypes[$nodeTypeData['name']])) {
                $filteredData[$nodeTypeData['name']] = $nodeTypeData;
            }
        }

        foreach ($nodeTypes AS $type => $val) {
            if (!isset($filteredData[$type]) && $result = $this->fetchUserNodeType($type)) {
                $filteredData[$type] = $result;
            }
        }

        return array_values($filteredData);
    }
    
		/**
     * Fetch a user-defined node-type definition.
     *
     * @param string $name
     * @return array
     */
    private function fetchUserNodeType($name)
    {
        //TODO  
        return array();
    }

    /**
     * Returns the path of all accessible reference properties in the workspace that point to the node.
     * If $weak_reference is false (default) only the REFERENCE properties are returned, if it is true, only WEAKREFERENCEs.
     * @param string $path
     * @param string $name name of referring WEAKREFERENCE properties to be returned; if null then all referring WEAKREFERENCEs are returned
     * @param boolean $weak_reference If true return only WEAKREFERENCEs, otherwise only REFERENCEs
     * @return array
     */
    protected function getNodeReferences($path, $name = null, $weak_reference = false)
    {
        $path = $this->validatePath($path);
        $type = $weak_reference ? \PHPCR\PropertyType::TYPENAME_WEAKREFERENCE : \PHPCR\PropertyType::TYPENAME_REFERENCE;

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll->createQueryBuilder()
                   ->select('_id')
                   ->field('path')->equals($path)
                   ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();
        $node = $query->getSingleResult();
        
        if (empty($node)) {
            throw new \PHPCR\ItemNotFoundException("Item ".$path." not found.");
        }
        
        $qb = $coll->createQueryBuilder()
                   ->field('props.type')->equals($type)
                   ->field('props.value')->equals($node['_id']->bin)
                   ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();
        
        $nodes = $query->getIterator();
        
        $references = array();
        foreach ($nodes as $node) {
            foreach ($node['props'] as $property) {
                if($property['type'] == $type){
                    if ($name === null || $property['name'] == $name) {
                        $references[] = $node['path'] . '/' . $property['name'];
                    }
                }
            }
        }
        
        
        return $references;
    }
    
    /**
     * Return the permissions of the current session on the node given by path.
     * The result of this function is an array of zero, one or more strings from add_node, read, remove, set_property.
     *
     * @param string $path the path to the node we want to check
     * @return array of string
     */
    public function getPermissions($path)
    {
        return array(
            \PHPCR\SessionInterface::ACTION_ADD_NODE,
            \PHPCR\SessionInterface::ACTION_READ,
            \PHPCR\SessionInterface::ACTION_REMOVE,
            \PHPCR\SessionInterface::ACTION_SET_PROPERTY
        );
    }
        
    /**
     * Get the repository descriptors from the jackrabbit server
     * This happens without login or accessing a specific workspace.
     *
     * @return Array with name => Value for the descriptors
     * @throws \PHPCR\RepositoryException if error occurs
     */
    public function getRepositoryDescriptors()
    {
        return array(
          'identifier.stability' => \PHPCR\RepositoryInterface::IDENTIFIER_STABILITY_INDEFINITE_DURATION,
          'jcr.repository.name'  => 'jackalope_mongodb',
          'jcr.repository.vendor' => 'Jackalope Community',
          'jcr.repository.vendor.url' => 'http://github.com/jackalope',
          'jcr.repository.version' => '1.0.0-DEV',
          'jcr.specification.name' => 'Content Repository for PHP',
          'jcr.specification.version' => 'false',
          'level.1.supported' => 'false',
          'level.2.supported' => 'false',
          'node.type.management.autocreated.definitions.supported' => 'true',
          'node.type.management.inheritance' => 'true',
          'node.type.management.multiple.binary.properties.supported' => 'true',
          'node.type.management.multivalued.properties.supported' => 'true',
          'node.type.management.orderable.child.nodes.supported' => 'false',
          'node.type.management.overrides.supported' => 'false',
          'node.type.management.primary.item.name.supported' => 'true',
          'node.type.management.property.types' => 'true',
          'node.type.management.residual.definitions.supported' => 'false',
          'node.type.management.same.name.siblings.supported' => 'false',
          'node.type.management.update.in.use.suported' => 'false',
          'node.type.management.value.constraints.supported' => 'false',
          'option.access.control.supported' => 'false',
          'option.activities.supported' => 'false',
          'option.baselines.supported' => 'false',
          'option.journaled.observation.supported' => 'false',
          'option.lifecycle.supported' => 'false',
          'option.locking.supported' => 'false',
          'option.node.and.property.with.same.name.supported' => 'false',
          'option.node.type.management.supported' => 'true',
          'option.observation.supported' => 'false',
          'option.query.sql.supported' => 'false',
          'option.retention.supported' => 'false',
          'option.shareable.nodes.supported' => 'false',
          'option.simple.versioning.supported' => 'false',
          'option.transactions.supported' => 'true',
          'option.unfiled.content.supported' => 'true',
          'option.update.mixin.node.types.supported' => 'true',
          'option.update.primary.node.type.supported' => 'true',
          'option.versioning.supported' => 'false',
          'option.workspace.management.supported' => 'true',
          'option.xml.export.supported' => 'false',
          'option.xml.import.supported' => 'false',
          'query.full.text.search.supported' => 'false',
          'query.joins' => 'false',
          'query.languages' => '',
          'query.stored.queries.supported' => 'false',
          'query.xpath.doc.order' => 'false',
          'query.xpath.pos.index' => 'false',
          'write.supported' => 'true',
        );
    }
}
