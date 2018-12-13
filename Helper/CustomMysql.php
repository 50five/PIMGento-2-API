<?php
/**
 * Class CustomMysql
 *
 * @category  Class
 * @package   Pimgento\Api\Helper
 * @author    50five <jelle.groenendal@50five.com>
 * @copyright 2018 50five
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
namespace Pimgento\Api\Helper;


use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\Helper\AbstractHelper;

class CustomMysql extends AbstractHelper
{
    /**
     * CustomMysql constructor.
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * @param AdapterInterface $connection
     * @param string $table
     * @param array $data
     * @return mixed
     * @throws \Zend_Db_Exception
     */
    public function insertMultiple($connection, $table, array $data)
    {
        $row = reset($data);
        // support insert syntaxes
        if (!is_array($row)) {
            return $connection->insert($table, $data);
        }

        // validate data array
        $cols = array_keys($row);
        $insertArray = [];
        foreach ($data as $row) {
            $line = [];
            if (array_diff($cols, array_keys($row))) {
                throw new \Zend_Db_Exception('Invalid data for insert');
            }
            foreach ($cols as $field) {
                $line[] = $row[$field];
            }
            $insertArray[] = $line;
        }
        unset($row);

        // Activate Replace on duplicate
        return $this->insertArray($connection, $table, $cols, $insertArray,1);
    }

    /**
     * @param AdapterInterface $connection
     * @param $table
     * @param array $columns
     * @param array $data
     * @param int $strategy
     * @return mixed
     * @throws \Zend_Db_Exception
     */
    public function insertArray(AdapterInterface $connection, $table, array $columns, array $data, $strategy = 0)
    {
        $values       = [];
        $bind         = [];
        $columnsCount = count($columns);
        foreach ($data as $row) {
            if ($columnsCount != count($row)) {
                throw new \Zend_Db_Exception('Invalid data for insert');
            }
            $values[] = $this->_prepareInsertData($row, $bind);
        }

        switch ($strategy) {
            case $connection::INSERT_ON_DUPLICATE:
                $query = $this->_getReplaceSqlQuery($connection, $table, $columns, $values);
                break;
            default:
                $query = $this->_getInsertSqlQuery($connection, $table, $columns, $values);
        }

        // execute the statement and return the number of affected rows
        $stmt   = $connection->query($query, $bind);
        $result = $stmt->rowCount();

        return $result;

    }

    /**
     * Prepare insert data
     *
     * @param mixed $row
     * @param array $bind
     * @return string
     */
    protected function _prepareInsertData($row, &$bind)
    {
        $row = (array)$row;
        $line = [];
        foreach ($row as $value) {
            if ($value instanceof \Zend_Db_Expr) {
                $line[] = $value->__toString();
            } else {
                $line[] = '?';
                $bind[] = $value;
            }
        }
        $line = implode(', ', $line);

        return sprintf('(%s)', $line);
    }

    /**
     * @param AdapterInterface $connection
     * @param $tableName
     * @param array $columns
     * @param array $values
     * @return string
     */
    protected function _getReplaceSqlQuery($connection, $tableName, array $columns, array $values)
    {
        $tableName = $connection->quoteIdentifier($tableName, true);
        $columns   = array_map([$connection, 'quoteIdentifier'], $columns);
        $columns   = implode(',', $columns);
        $values    = implode(', ', $values);

        $replaceSql = sprintf('REPLACE INTO %s (%s) VALUES %s', $tableName, $columns, $values);

        return $replaceSql;
    }

    /**
     * @param AdapterInterface $connection
     * @param $connection
     * @param $tableName
     * @param array $columns
     * @param array $values
     * @return string
     */
    protected function _getInsertSqlQuery($connection, $tableName, array $columns, array $values)
    {
        $tableName = $connection->quoteIdentifier($tableName, true);
        $columns   = array_map([$this, 'quoteIdentifier'], $columns);
        $columns   = implode(',', $columns);
        $values    = implode(', ', $values);

        $insertSql = sprintf('INSERT INTO %s (%s) VALUES %s', $tableName, $columns, $values);

        return $insertSql;
    }
}