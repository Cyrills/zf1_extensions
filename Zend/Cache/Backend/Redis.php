<?php

/**
 * @see Zend_Cache_Backend
 */
#require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
#require_once 'Zend/Cache/Backend/ExtendedInterface.php';

namespace Zend\Cache\Backend;
use Zend\Cache;

/**
 * Redis adapter for Zend_Cache
 * 
 * @author Soin Stoiana
 * @author Colin Mollenhour
 * @version 0.0.1
 */
class Redis extends AbstractBackend implements ExtendedBackend
{

    const SET_IDS  = 'zc:ids';
    const SET_TAGS = 'zc:tags';

    const PREFIX_DATA     = 'zc:d:';
    const PREFIX_MTIME    = 'zc:m:';
    const PREFIX_TAG_IDS  = 'zc:ti:';
    const PREFIX_ID_TAGS  = 'zc:it:';

    /** @var Credis_Client */
    protected $_redis;

    /** @var bool */
    protected $_notMatchingTags = FALSE;

    /** @var bool */
    protected $_exactMtime = FALSE;

    /**
     * Contruct Zend_Cache Redis backend
     * @param array $options
     * @return \Zend\Cache\Backend\Redis
     */
    public function __construct($options = array())
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }

        if ( empty($options['server']) ) {
            Cache\Cache::throwException('Redis \'server\' not specified.');
        }

        if ( empty($options['port']) && substr($options['server'],0,1) != '/' ) {
            Cache\Cache::throwException('Redis \'port\' not specified.');
        }

        if( isset($options['timeout'])) {
          $this->_redis = new \Credis\Client($options['server'], $options['port'], $options['timeout']);
        } else {
          $this->_redis = new \Credis\Client($options['server'], $options['port']);
        }

        if ( isset($options['force_standalone']) && $options['force_standalone']) {
          $this->_redis->forceStandalone();
        }

        if ( ! empty($options['database'])) {
            $this->_redis->select( (int) $options['database']) or Cache\Cache::throwException('The redis database could not be selected.');
        }

        if ( isset($options['notMatchingTags']) ) {
            $this->_notMatchingTags = (bool) $options['notMatchingTags'];
        }

        if ( isset($options['exactMtime']) ) {
            $this->_exactMtime = (bool) $options['exactMtime'];
        }

        if ( isset($options['automatic_cleaning_factor']) ) {
            $this->_options['automatic_cleaning_factor'] = (int) $options['automatic_cleaning_factor'];
        } else {
            $this->_options['automatic_cleaning_factor'] = 20000;
        }
    }
    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $data = $this->_redis->get(self::PREFIX_DATA . $id);
        if($data === NULL) {
            return FALSE;
        }
        return $data;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return bool|int False if record is not available or "last modified" timestamp of the available cache record
     */
    public function test($id)
    {
        if($this->_exactMtime) {
            $mtime = $this->_redis->get(self::PREFIX_MTIME . $id);
            return ($mtime ? $mtime : FALSE);
        }
        else {
            $exists = $this->_redis->exists(self::PREFIX_DATA . $id);
            return ($exists ? time() : FALSE);
        }
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if(!is_array($tags)) $tags = array($tags);

        $lifetime = $this->getLifetime($specificLifetime);

        // Get list of tags previously assigned
        $oldTags = (array) $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id);

        $this->_redis->pipeline();

        // Set the data
        if ($lifetime) {
            $this->_redis->setex(self::PREFIX_DATA . $id, $lifetime, $data);
        } else {
            $this->_redis->set(self::PREFIX_DATA . $id, $data);
        }

        // Set the modified time
        if($this->_exactMtime) {
            if ($lifetime) {
                $this->_redis->setex(self::PREFIX_MTIME . $id, $lifetime, time());
            } else {
                $this->_redis->set(self::PREFIX_MTIME . $id, time());
            }
        }

        // Process added tags
        if ($addTags = array_diff($tags, $oldTags))
        {
            // Update the list with all the tags
            $this->_redis->sAdd( self::SET_TAGS, $addTags);

            // Update the list of tags for this id
            $this->_redis->sAdd( self::PREFIX_ID_TAGS . $id, $addTags);

            // Update the id list for each tag
            foreach($addTags as $tag)
            {
                $this->_redis->sAdd(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Expire tags set at same time as data
        if (count($tags) > 0 && $lifetime) {
            $this->_redis->expire(self::PREFIX_ID_TAGS . $id, $lifetime);
        }

        // Process removed tags
        if ($remTags = array_diff($oldTags, $tags))
        {
            // Remove tags from id's tag set
            $this->_redis->sRem( self::PREFIX_ID_TAGS . $id, $remTags);

            // Update the id list for each tag
            foreach($remTags as $tag)
            {
                $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Update the list with all the ids
        if($this->_notMatchingTags) {
            $this->_redis->sAdd(self::SET_IDS, $id);
        }

        $this->_redis->exec();

        return TRUE;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        // Get list of tags for this id
        $tags = $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id);

        $this->_redis->pipeline();

        // Remove data
        $this->_redis->del( self::PREFIX_DATA . $id );

        // Remove mtime
        if($this->_exactMtime) {
            $this->_redis->del( self::PREFIX_MTIME . $id );
        }

        // Remove id from list of all ids
        if($this->_notMatchingTags) {
            $this->_redis->sRem( self::SET_IDS, $id );
        }

        // Update the id list for each tag
        foreach($tags as $tag) {
            $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
        }

        // Remove list of tags
        $this->_redis->del( self::PREFIX_ID_TAGS . $id );

        $result = $this->_redis->exec();

        return (bool) $result[0];
    }

    protected function _removeByNotMatchingTags($tags)
    {
        $ids = $this->getIdsNotMatchingTags($tags);
        if($ids)
        {
            $this->_redis->pipeline();

            // Remove data
            $this->_redis->del( $this->_preprocessIds($ids));

            // Remove mtimes
            if($this->_exactMtime) {
                $this->_redis->del( $this->_preprocessMtimes($ids));
            }

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redis->sRem( self::SET_IDS, $ids);
            }

            // Remove tag lists for all ids
            $this->_redis->del( $this->_preprocessIdTags($ids));

            $this->_redis->exec();
        }
    }

    protected function _removeByMatchingTags($tags)
    {
        $ids = $this->getIdsMatchingTags($tags);
        if($ids)
        {
            $this->_redis->pipeline();

            // Remove data
            $this->_redis->del( $this->_preprocessIds($ids));

            // Remove mtimes
            if($this->_exactMtime) {
                $this->_redis->del( $this->_preprocessMtimes($ids));
            }

            // Remove tag lists for all ids
            $this->_redis->del( $this->_preprocessIdTags($ids));

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redis->sRem( self::SET_IDS, $ids);
            }

            $this->_redis->exec();
        }
    }

    protected function _removeByMatchingAnyTags($tags)
    {
        $ids = $this->getIdsMatchingAnyTags($tags);

        $this->_redis->pipeline();

        if($ids)
        {
            // Remove data
            $this->_redis->del( $this->_preprocessIds($ids));

            // Remove mtimes
            if($this->_exactMtime) {
                $this->_redis->del( $this->_preprocessMtimes($ids));
            }

            // Remove tag lists for all ids
            $this->_redis->del( $this->_preprocessIdTags($ids));

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redis->sRem( self::SET_IDS, $ids);
            }
        }

        // Remove tag id lists
        $this->_redis->del( $this->_preprocessTagIds($tags));

        // Remove tags from list of tags
        $this->_redis->sRem( self::SET_TAGS, $tags);

        $this->_redis->exec();
    }

    protected function _collectGarbage()
    {
        // Clean up expired keys from tag id set and global id set
        $exists = array();
        $tags = (array) $this->_redis->sMembers(self::SET_TAGS);
        foreach($tags as $tag)
        {
            // Get list of expired ids for each tag
            $tagMembers = $this->_redis->sMembers(self::PREFIX_TAG_IDS . $tag);
            $expired = array();
            if(count($tagMembers)) {
                foreach($tagMembers as $id) {
                    if( ! isset($exists[$id])) {
                        $exists[$id] = $this->_redis->exists(self::PREFIX_DATA . $id);
                    }
                    if( ! $exists[$id]) {
                        $expired[] = $id;
                    }
                }
                if( ! count($expired)) continue;
            }

            $this->_redis->pipeline();

            // Remove empty tags or completely expired tags
            if( ! count($tagMembers) || count($expired) == count($tagMembers)) {
                $this->_redis->del(self::PREFIX_TAG_IDS . $tag);
                $this->_redis->sRem(self::SET_TAGS, $tag);
            }
            // Clean up expired ids from tag ids set
            else {
                $this->_redis->sRem( self::PREFIX_TAG_IDS . $tag, $expired);
            }

            // Clean up expired ids from ids set
            if($this->_notMatchingTags) {
                $this->_redis->sRem( self::SET_IDS, $expired);
            }

            $this->_redis->exec();
        }

        // Clean up global list of ids for ids with no tag
        if($this->_notMatchingTags) {
            // TODO
        }
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
     * 'matchingTag'    => supported
     * 'notMatchingTag' => supported
     * 'matchingAnyTag' => supported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Cache\Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Cache\Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if( $tags && ! is_array($tags)) {
            $tags = array($tags);
        }

        if($mode == Cache\Cache::CLEANING_MODE_ALL) {
            return ($this->_redis->flushDb() == 'OK');
        }

        if($mode == Cache\Cache::CLEANING_MODE_OLD) {
            $this->_collectGarbage();
            return TRUE;
        }

        if( ! count($tags)) {
            return TRUE;
        }

        $result = TRUE;

        switch ($mode)
        {
            case Cache\Cache::CLEANING_MODE_MATCHING_TAG:

                $this->_removeByMatchingTags($tags);
                break;

            case Cache\Cache::CLEANING_MODE_NOT_MATCHING_TAG:

                $this->_removeByNotMatchingTags($tags);
                break;

            case Cache\Cache::CLEANING_MODE_MATCHING_ANY_TAG:

                $this->_removeByMatchingAnyTags($tags);
                break;

            default:
                Cache\Cache::throwException('Invalid mode for clean() method: '.$mode);
        }
        return (bool) $result;
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return TRUE;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Cache\Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > 2592000) {
            Cache\Cache::throwException('Redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        if($this->_notMatchingTags) {
            return (array) $this->_redis->sMembers(self::SET_IDS);
        } else {
            $keys = $this->_redis->keys(self::PREFIX_DATA . '*');
            $prefixLen = strlen(self::PREFIX_DATA);
            foreach($keys as $index => $key) {
                $keys[$index] = substr($key, $prefixLen);
            }
            return $keys;
        }
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return (array) $this->_redis->sMembers(self::SET_TAGS);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return (array) $this->_redis->sInter( $this->_preprocessTagIds($tags) );
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a negated logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if( ! $this->_notMatchingTags) {
            Cache\Cache::throwException("notMatchingTags is currently disabled.");
        }
        return (array) $this->_redis->sDiff( self::SET_IDS, $this->_preprocessTagIds($tags) );
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return (array) $this->_redis->sUnion( $this->_preprocessTagIds($tags));
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Cache\Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        $mtime = $this->test($id);
        if( ! $mtime) {
            return FALSE;
        }
        $ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        $tags = (array) $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id );

        return array(
            'expire' => time() + $ttl,
            'tags' => $tags, 
            'mtime' => $mtime,
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        if ($ttl != -1) {
            $expireAt = time() + $ttl + $extraLifetime;
            $result = $this->_redis->expireAt(self::PREFIX_DATA . $id, $expireAt);
            if($result) {
                if($this->_exactMtime) {
                    $this->_redis->expireAt(self::PREFIX_MTIME . $id, $expireAt);
                }
                $this->_redis->expireAt(self::PREFIX_ID_TAGS . $id, $expireAt);
            }
            return (bool) $result;
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => ($this->_options['automatic_cleaning_factor'] > 0),
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => true,
        );
    }

    protected function _preprocess(&$item, $index, $prefix)
    {
        $item = $prefix . $item;
    }

    protected function _preprocessItems($items, $prefix)
    {
        array_walk( $items, array($this, '_preprocess'), $prefix);
        return $items;
    }

    protected function _preprocessIds($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_DATA);
    }

    protected function _preprocessMtimes($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_MTIME);
    }

    protected function _preprocessIdTags($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_ID_TAGS);
    }

    protected function _preprocessTagIds($tags)
    {
        return $this->_preprocessItems($tags, self::PREFIX_TAG_IDS);
    }

    /**
     * Required to pass unit tests
     *
     * @param  string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->del(self::PREFIX_DATA . $id);
        if($this->_exactMtime) {
            $this->_redis->del(self::PREFIX_MTIME . $id);
        }
        $this->_redis->del(self::PREFIX_ID_TAGS . $id);
    }

}
