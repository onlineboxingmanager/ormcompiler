<?php
/*
 * $Id: MySQLiTableInfo.php,v 1.3 2006/01/17 19:44:39 hlellelid Exp $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://creole.phpdb.org>.
 */

require_once CREOLE_BASEPATH.'/metadata/TableInfo.php';

/**
 * MySQLi implementation of TableInfo.
 *
 * @author    Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @version   $Revision: 1.3 $
 * @package   creole.drivers.mysqli.metadata
 */
class MySQLiTableInfo extends TableInfo {
    /** Loads the columns for this table. */
    protected function initColumns()
    {
        require_once CREOLE_BASEPATH.'/metadata/ColumnInfo.php';
        require_once CREOLE_BASEPATH.'/drivers/mysql/MySQLTypes.php';

        // To get all of the attributes we need, we use
        // the MySQL "SHOW COLUMNS FROM $tablename" SQL.
        $res = mysqli_query($this->conn->getResource(), "SHOW COLUMNS FROM " . $this->name);

        $defaults = array();
        $nativeTypes = array();
        $precisions = array();

        while($row = mysqli_fetch_assoc($res))
        {
            $name = $row['Field'];
            $default = $row['Default'];
            $is_nullable = ( $row['Null'] == 'YES' );
	        $is_autoincrement = ( $row['Extra'] == 'auto_increment' );

            $size = null;
            $precision = null;
            $scale = null;

	        $nativeType = $row['Type'];

            if ( preg_match('/^(\w+)[\(]?([\d,]*)[\)]?( |$)/', $row['Type'], $matches) )
            {
                //            colname[1]   size/precision[2]
                $nativeType = $matches[1];
                if ( $matches[2] )
                {
	                $size = (int) $matches[2];

                	if ( ($cpos = strpos($matches[2], ',')) !== false)
                    {
                        $size = (int) substr($matches[2], 0, $cpos);
                        $precision = $size;
                        $scale = (int) substr($matches[2], $cpos + 1);
                    }
                }
            }

            elseif (preg_match('/^(\w+)\(/', $row['Type'], $matches))
            {
                $nativeType = $matches[1];
            }

            $this->columns[$name] = new ColumnInfo($this, $name, MySQLTypes::getType($nativeType), $nativeType, $size, $precision, $scale, $is_nullable, $default, $is_autoincrement);
        }

        $this->colsLoaded = true;
    }

    /** Loads the primary key information for this table. */
    protected function initPrimaryKey()
    {
        require_once CREOLE_BASEPATH.'/metadata/PrimaryKeyInfo.php';

        // columns have to be loaded first
        if (!$this->colsLoaded) {
            $this->initColumns();
        }

        // Primary Keys
        $res = mysqli_query($this->conn->getResource(), "SHOW KEYS FROM " . $this->name);

        // Loop through the returned results, grouping the same key_name together
        // adding each column for that key.
        while($row = mysqli_fetch_assoc($res))
        {
        	if( $row['Key_name'] != 'PRIMARY' )
        		continue;

            $name = $row["Column_name"];
            if ( !isset($this->primaryKey) )
                $this->primaryKey = new PrimaryKeyInfo($name);

            $this->primaryKey->addColumn($this->columns[ $name ]);
        }

        $this->pkLoaded = true;
    }

    /** Loads the indexes for this table. */
    protected function initIndexes() {
        require_once CREOLE_BASEPATH.'/metadata/IndexInfo.php';

        // columns have to be loaded first
        if (!$this->colsLoaded) {
            $this->initColumns();
        }
        
        // Indexes
        $res = mysqli_query($this->conn->getResource(), "SHOW INDEX FROM " . $this->name);

        // Loop through the returned results, grouping the same key_name together
        // adding each column for that key.
        while($row = mysqli_fetch_assoc($res)) {
            $name = $row["Column_name"];

            if (!isset($this->indexes[$name])) {
                $this->indexes[$name] = new IndexInfo($name);
            }

            $this->indexes[$name]->addColumn($this->columns[ $name ]);
        }

        $this->indexesLoaded = true;
    }


	/**
	 * Load foreign keys for supporting versions of MySQL.
	 * @author Tony Bibbs
	 */
	protected function initForeignKeys() {

		// First make sure we have supported version of MySQL:
		$res = mysqli_query("SELECT VERSION()");
		$res = mysqli_query($this->conn->getResource(), "SELECT VERSION()");
		$row = mysqli_fetch_row($res);

		// Yes, it is OK to hardcode this...this was the first version of MySQL
		// that supported foreign keys
		if ($row[0] < '3.23.44' OR 1 == 1 ) {
			$this->fksLoaded = true;
			#return;
		}
		include_once CREOLE_BASEPATH.'/metadata/ForeignKeyInfo.php';

		// columns have to be loaded first
		if (!$this->colsLoaded)
			$this->initColumns();

		// Get the CREATE TABLE syntax
		$res = mysqli_query($this->conn->getResource(), "SHOW CREATE TABLE `" . $this->name . "`");
		$row = mysqli_fetch_row($res);

		$matches = [];

		// Get the information on all the foreign keys
		$regEx = '/FOREIGN KEY \(`([^`]*)`\) REFERENCES `([^`]*)` \(`([^`]*)`\)(.*)/';
		if ( preg_match_all($regEx, $row[1],$matches))
		{
			$tmpArray = array_keys($matches[0]);
			foreach ($tmpArray as $curKey)
			{
				$name = $matches[1][$curKey];
				$ftbl = $matches[2][$curKey];
				$fcol = $matches[3][$curKey];
				$fkey = $matches[4][$curKey];

				if (!isset($this->foreignKeys[$name]))
				{
					$this->foreignKeys[$name] = new ForeignKeyInfo($name);
					if ($this->database->hasTable($ftbl)) {
						$foreignTable = $this->database->getTable($ftbl);
					} else {
						$foreignTable = new MySQLiTableInfo($this->database, $ftbl);
						$this->database->addTable($foreignTable);
					}

					if ($foreignTable->hasColumn($fcol))
					{
						$foreignCol = $foreignTable->getColumn($fcol);
					}

					else
					{
						$foreignCol = new ColumnInfo($foreignTable, $fcol);
						$foreignTable->addColumn($foreignCol);
					}

					//typical for mysql is RESTRICT
					$fkactions = array(
						'ON DELETE'	=> ForeignKeyInfo::RESTRICT,
						'ON UPDATE'	=> ForeignKeyInfo::RESTRICT,
					);

					if ($fkey)
					{
						//split foreign key information -> search for ON DELETE and afterwords for ON UPDATE action
						foreach (array_keys($fkactions) as $fkaction) {
							$result = NULL;
							preg_match('/' . $fkaction . ' (' . ForeignKeyInfo::CASCADE . '|' . ForeignKeyInfo::SETNULL . ')/', $fkey, $result);
							if ($result && is_array($result) && isset($result[1])) {
								$fkactions[$fkaction] = $result[1];
							}
						}
					}

					$this->foreignKeys[$name]->addReference($this->columns[$name], $foreignCol, $fkactions['ON DELETE'], $fkactions['ON UPDATE']);
				}
			}
		}
		$this->fksLoaded = true;
	}
}
