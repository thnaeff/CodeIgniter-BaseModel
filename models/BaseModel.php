<?php
defined('BASEPATH') or exit('No direct script access allowed');


/**
 * An extension to the basic CRUD Model.<br />
 * Features:<br />
 * - Cascade delete. Define relationships with other tables where rows have to be deleted if a row in the current table gets deleted (also works with soft deletes/undeletes)<br />
 * - Soft delete. Deleting a row only sets a flag to mark it as deleted. Rows marked as deleted are not retrieved by default.<br />
 * - Soft undelete. Reversing a soft delete (marking the row as not deleted)<br />
 * - Categories<br />
 * - Sort order and movign rows up and down<br />
 * - Checking for the existence of a row<br />
 * - Retrieving date range<br />
 * - Toggle<br />
 * - Pagination support
 * - <br />
 * - <br />
 * <br />
 *
 * @link http://github.com/thnaeff/CodeIgniter-BaseModel
 * @copyright Copyright (c) 2015, Thomas Naeff
 *
 */
class BaseModel extends CRUDModel {

	/**
	 * The field/column name of the soft delete flag. If set to NULL, soft delete is not used.
	 */
	protected $soft_delete_field = NULL;

	/**
	 * If set to TRUE, the returned records include deleted records.
	 */
	private $_temporary_with_deleted = FALSE;

	/**
	 * If set to TRUE, only deleted records are retrieved
	 */
	private $_temporary_only_deleted = FALSE;

	/**
	 * If set to TRUE and soft delete is activated (soft_delete_field is set to a value),
	 * soft delete is ignored and the record is deleted from the database
	 */
	private $_temporary_hard_delete = FALSE;

	/**
	 * The field/column name of the category index. If set to NULL, categories are not used.
	 */
	protected $category_field = NULL;

	/**
	 * The category ID
	 */
	private $_temporary_category_id = NULL;

	/**
	 * The field/column name of the sort order index. If set to NULL, sort order is not used.
	 */
	protected $sort_order_field = NULL;


	/**
	 * Database table delete relations.
	 * Similar as the belongs_to and has_many definitions, this definition deletes any
	 * related data (instead of retrieving it).
	 */
	protected $cascade_delete = array();

	protected $records_per_page = 0;

	private $_current_page = 0;
	private $_number_of_pages = 0;


	/**
	 *
	 *
	 * @param string $table_name The table name can be provided here. If the table name is not provided,
	 * the name is guessed by pluralizing the model name
	 */
	public function __construct($table_name = NULL) {
		$this->load->database();

		parent::__construct($table_name);

		// Generate primary key with singular table name and _id
		$this->primary_key = singular($this->_table) . '_id';

		//Additional events
		$this->events['before_undelete'] = NULL;
		$this->events['after_undelete'] = NULL;

	}

	/**
	 * Override
	 *
	 * (non-PHPdoc)
	 * @see CRUDModel::reset()
	 */
	public function reset() {
		parent::reset();
		$this->_temporary_with_deleted = FALSE;
		$this->_temporary_only_deleted = FALSE;
		$this->_temporary_category_id = NULL;
		$this->_temporary_hard_delete = FALSE;
	}

	/**
	 * Sets the flag so that also records marked as deleted are returned.
	 *
	 * @param boolean $with_deleted
	 * @return BaseModel
	 */
	public function with_deleted($with_deleted = TRUE) {
		$this->_temporary_with_deleted = $with_deleted;
		return $this;
	}

	/**
	 * Sets the flag so that only records marked as deleted are returned.
	 *
	 * @param boolean $only_deleted
	 * @return BaseModel
	 */
	public function only_deleted($only_deleted = TRUE) {
		$this->_temporary_only_deleted = $only_deleted;
		return $this;
	}

	/**
	 * Ignores the soft-delete (if activated) and deletes the record from the database
	 *
	 * @return BaseModel
	 */
	public function hard_delete() {
		$this->_temporary_hard_delete = true;
		return $this;
	}

	/**
	 * Activates pagination and sets the number of records per page. To deactivate pagination,
	 * set this value to 0.
	 *
	 * @param int $record_count
	 */
	public function pagination($recordCount) {
		if ($recordCount < 0) {
			$recordCount = 0;
		}

		$this->records_per_page = $recordCount;
	}

	/**
	 * If pagination is activated, this method sets the current page.
	 *
	 * @param int $page
	 */
	public function setPage($page) {
		if ($page <= 0) {
			$page = 1;
		} else if ($page > $this->_number_of_pages) {
			$currentPage = $this->_number_of_pages;
		}

		$this->_current_page = $page;
	}

	/**
	 * Returns the current page if pagination is activated
	 *
	 * @return number|int
	 */
	public function getPage() {
		return $this->_current_page;
	}

	/**
	 * Returns the number of pages if pagination is activated. The number of pages
	 * is only accurate after a get() call, because it applies to the database
	 * query.
	 *
	 * @return number|int
	 */
	public function getPagesCount() {
		return $this->_number_of_pages;
	}

	/*----------------------------------------------------------------------------------------
	 * CRUD Function overrides
	 *
	 */

	/**
	 *
	 *
	 * {@inheritDoc}
	 * @see CRUDModel::get()
	 */
	public function get($primary_values = NULL, $limit=0, $offset=0) {

		$this->filter_soft_delete();
		$this->filter_category();

		//Set order-by if needed
		if ($this->sort_order_field != NULL) {
			$this->database->order_by($this->sort_order_field);
		}

		$result = null;

		//Pagination?
		if ($this->records_per_page > 0) {
			//Number of records, starting at current offset
			$result = parent::get($primary_values, $this->records_per_page, ($this->_current_page - 1) * $this->records_per_page);

			if ($this->get_total_records() > $this->records_per_page) {
				$this->_number_of_pages = ceil($this->get_total_records() / $this->records_per_page);

				//Makes sure the current page does not exceed the highest page
				if ($this->_current_page > $this->_number_of_pages) {
					$this->_current_page = $this->_number_of_pages;
				}
			}
		} else {
			$result = parent::get($primary_values);

			$this->_number_of_pages = 1;
			$this->_current_page = 1;
			$this->records_per_page = $this->get_total_records();
		}


		return $result;
	}

	/**
	 * Override
	 *
	 * (non-PHPdoc)
	 * @see CRUDModel::insert()
	 */
	public function insert($data) {

		if ($this->sort_order_field != NULL) {
			//Sort order +1 of current max sort order number
			$this->database->set($this->sort_order_field, '(SELECT IFNULL(MAX(t.' . $this->sort_order_field . '), 0) + 1 FROM ' . $this->_table . ' as t)', FALSE);
		}

		return parent::insert($data);

	}

	/**
	 * Override
	 *
	 * (non-PHPdoc)
	 * @see CRUDModel::delete()
	 */
	public function delete($primary_values = null, $rows = null) {
		$primary_values = $this->primary_values_from_rows($rows, $primary_values);

		$rows_to_delete = null;
		if (count($this->cascade_delete) > 0) {
			//For the cascade delete, the primary values are needed.
			//Since an external where statement might have been defined (in addition to provided primary values)
			//for this delete, the primary values have to be retrieved first.
			$this->set_where($this->primary_key, $primary_values);
			$this->database->select([$this->primary_key, $this->sort_order_field]);
			$rows_to_delete = $this->database->get($this->_table)->result_array();
			$this->save_query();

			//All primary values
			$primary_values = array();
			foreach ($rows_to_delete as $result) {
				$primary_values[] = $result[$this->primary_key];
			}
		}


		if ($this->soft_delete_field != NULL && ! $this->_temporary_hard_delete) {
			//Soft-delete (mark record (update) as deleted instead of deleting the record)

			$primary_values = $this->trigger('before_delete', $primary_values);
			if ($primary_values === FALSE) {
				$this->reset();
				return FALSE;
			}

			// Limit to primary key(s) (if provided)
			$this->set_where($this->primary_key, $primary_values);

			$result = $this->database->update($this->_table, array($this->soft_delete_field=>TRUE));
			$this->save_query();
			$this->reset();

			$this->trigger('after_delete', array($primary_values, $result));

			$this->cascade_delete_undelete($rows_to_delete, true);

			return $result;
		} else if ($this->sort_order_field != NULL) {
			//When the sort order is used, rows have to be deleted individually in order to update the sort order
			//of the remaining rows.

			$this->database->trans_start();
			$result = array();

			//Update all sort orders and delete the records
			foreach ($rows_to_delete as $row) {
				$primary_value = $row[$this->primary_key];
				$sort_order = $row[$this->sort_order_field];

				//Update all following sort order with a sort order decreased by 1
				$this->database->set($this->sort_order_field, $this->sort_order_field . ' - 1', false);
				$this->database->where($this->sort_order_field . ' >', $sort_order);
				$this->database->update($this->_table);
				$this->save_query();

				//Delete
				$result[] = parent::delete($primary_value);
				$this->cascade_delete_undelete($rows_to_delete, true);
			}

			$this->database->trans_complete();
			return $result;
		} else {
			//Delete record

			$ret = parent::delete($primary_values);
			$this->cascade_delete_undelete($rows_to_delete, true);
			return $ret;
		}
	}

	/*----------------------------------------------------------------------------------------
	 * Additional "CRUD" methods
	 *
	 */

	/**
	 * Reverses a soft-delete. Only works if a soft delete field is defined.
	 *
	 *
	 * @param string $primary_values
	 * @param array $rows
	 * @return
	 */
	public function undelete($primary_values = null, $rows = null) {

		$rows_to_delete = null;
		if (! empty($this->cascade_delete)) {
			//For the cascade delete, the primary values are needed.
			//Since an external where statement might have been defined (in addition to provided primary values)
			//for this delete, the primary values have to be retrieved first.
			$this->set_where($this->primary_key, $primary_values);
			$this->database->select([$this->primary_key, $this->sort_order_field]);
			$rows_to_delete = $this->database->get($this->_table)->result_array();
			$this->save_query();

			//All primary values
			$primary_values = array();
			foreach ($rows_to_delete as $result) {
				$primary_values[] = $result[$this->primary_key];
			}
		}


		if ($this->soft_delete_field != NULL) {
			//Soft-delete (mark record (update) as deleted instead of deleting the record)

			$primary_values = $this->primary_values_from_rows($rows, $primary_values);

			$primary_values = $this->trigger('before_undelete', $primary_values);
			if ($primary_values === FALSE) {
				$this->reset();
				return FALSE;
			}

			// Limit to primary key(s) (if provided)
			$this->set_where($this->primary_key, $primary_values);

			$result = $this->database->update($this->_table, array($this->soft_delete_field=>FALSE));
			$this->save_query();
			$this->reset();

			$this->trigger('after_undelete', array($primary_values, $result));

			$this->cascade_delete_undelete($rows_to_delete, false);

			return $result;
		}
	}

	/**
	 * Performs an update with only the changed values (all values in newRows which
	 * do not exist in oldRows).
	 *
	 * @param array $oldRows
	 * @param array $newRows
	 * @return array The row values which have been used for the update
	 */
	public function save($oldRows, $newRows) {

		//Only update changed fields
		$dataToSave = $this->getChanges($newRows, $oldRows);

		$this->update($dataToSave);

		return $dataToSave;
	}

	/**
	 * Computes the changes between the old data and the new data. The returned
	 * array contains the values of the new row which differ from the value
	 * of the old rows.
	 *
	 * @param array $oldRows
	 * @param array $newRows
	 * @return Returns An array of the difference between the two arrays
	 */
	public static function getChanges($newRows, $oldRows) {
		return self::array_diff_assoc_recursive($newRows, $oldRows);
	}


	/*----------------------------------------------------------------------------------------
	 * Useful database functions
	 */

	/**
	 * Checks if a row exists for the current conditions.
	 *
	 * @param string $primary_values
	 * @return TRUE if a row exits, FALSE if not
	 */
	public function exists($primary_values = null) {
		$this->database->select($this->primary_key);
		$result = $this->get($primary_values);

		if ($result == NULL || $result === FALSE || count($result) == 0) {
			return false;
		}

		return true;
	}

	/**
	 * Returns results which are within (inclusive) the given date range. <br />
	 * <br />
	 * TO_COLUMN >= DATE <= FROM_COLUMN
	 *
	 * @param string $from_column
	 * @param string $to_column
	 * @param string $date
	 * @return var/boolean Returns the query result as array/object, or FALSE if the execution got interrupted by an event.
	 */
	public function getWithinDateRange($from_column, $to_column, $date='NOW') {
		$dt = new DateTime($date);
		$d = $dt->format('Y-m-d HH:mm:ss');

		if ($from_column != null) {
			$this->database->where($from_column . ' <=', $d);
		}

		if ($to_column != null) {
			$this->database->where($to_column . ' >=', $d);
		}

		return $this->get();
	}

	/**
	 * Toggles the value of the column with the given key. The column should be of type BOOLEAN.
	 *
	 * @param string $column_key
	 * @param string/array $primary_values
	 */
	public function toggle($column_key, $primary_values = null) {
		$this->filter_soft_delete();
		$this->filter_category();

		$this->db->set($column_key, 'NOT ' . $column_key, false);

		$this->db->update($this->_table);
	}

	/*----------------------------------------------------------------------------------------
	 * Tools
	 */

	/**
	 * Rows retrieved from the database usually have the row number as key. To use any
	 * of the field values as key, use this method.
	 *
	 * @param array $rows The source rows array
	 * @param string $idField
	 * @return The new rows array
	 */
	public static function convertToIdAsKey($rows, $idField='id') {
		$newRows = array();

		foreach ($rows as $row) {
			$newRows[$row[$idField]] = $row;
		}

		return $newRows;
	}

	/*----------------------------------------------------------------------------------------
	 *
	 */

	/**
	 * Sets the category to limit the result to for the next get call.
	 *
	 *
	 * @param unknown $category_id The category to filter for. Can be set to NULL to ignore categories.
	 * @return BaseModel
	 */
	public function category($category_id) {
		$this->_temporary_category_id = $category_id;
		return $this;
	}

	/*----------------------------------------------------------------------------------------
	 * Sort order
	 */

	/**
	 * Moves the row with the given primary key one row up.<br />
	 * Only works if a sort order field is defined.<br />
	 * If a category is selected, the row is moved only within that category. The same applies to
	 * soft deleted rows.<br />
	 * <br />
	 * <br />
	 *
	 * @param unknown $primary_key
	 * @return boolean
	 */
	public function move_up($primary_key) {
		if ($this->sort_order_field == NULL) {
			return;
		}

		$this->database->trans_start();

		//Get the sort order of the row to move
		$this->database->select($this->sort_order_field);
		$this->database->where($this->primary_key, $primary_key);
		$query = $this->database->get($this->_table);
		$this->save_query();

		if ($query->num_rows() == 0) {
			return false;
		}

		$sort_order = $query->row_array()[$this->sort_order_field];

		if ($sort_order <= 1) {
			//Can't go up further
			return false;
		}

		$this->filter_soft_delete();
		$this->filter_category();

		//Retrieve the row "above" the current row. The row above could be any row above it, depending
		//on if categories/soft deletes/other conditions are used.
		$this->database->select($this->sort_order_field);
		$this->database->limit(1);
		$this->database->where($this->sort_order_field . ' <', $sort_order);
		//Descending order so that the highest sort order is returned (the one above it)
		$this->database->order_by($this->sort_order_field . ' DESC');
		$row = $this->database->get($this->_table)->row_array();
		$this->save_query();

		if ($row == NULL) {
			//Can't go up further
			return false;
		}

		$previousRowSortOrder = $row[$this->sort_order_field];


		//Increase all sort orders from (and including) the previous row up to before the row which has to be moved
		$this->database->set($this->sort_order_field, $this->sort_order_field . ' + 1', false);
		$this->database->where($this->sort_order_field . ' >=', $previousRowSortOrder);
		$this->database->where($this->sort_order_field . ' <', $sort_order);
		$this->database->update($this->_table);
		$this->save_query();

		//Set the sort order of the moving row to the sort order of the previous row
		$this->database->set($this->sort_order_field, $previousRowSortOrder);
		$this->database->where($this->primary_key, $primary_key);
		$this->database->update($this->_table);
		$this->save_query();

		$this->database->trans_complete();

	}

	/**
	 * Moves the row with the given primary key one row down.<br />
	 * Only works if a sort order field is defined.<br />
	 * If a category is selected, the row is moved only within that category.<br />
	 *
	 *
	 * @param unknown $primary_key
	 * @return boolean
	 */
	public function move_down($primary_key) {
		if ($this->sort_order_field == NULL) {
			return;
		}

		$this->database->trans_start();

		//Get the sort order of the row to move
		$this->database->select($this->sort_order_field);
		$this->database->where($this->primary_key, $primary_key);
		$query = $this->database->get($this->_table);
		$this->save_query();

		if ($query->num_rows() == 0) {
			return false;
		}

		$sort_order = $query->row_array()[$this->sort_order_field];

		//Get the max sort order
		$this->database->select_max($this->sort_order_field, 'max_sort_order');
		$query = $this->database->get($this->_table);
		$this->save_query();

		$max_sort_order = $query->row_array()['max_sort_order'];

		if ($sort_order >= $max_sort_order) {
			//Can't go down further
			return false;
		}

		$this->filter_soft_delete();
		$this->filter_category();

		//Retrieve the row "below" the current row. The row below could be any row below it, depending
		//on if categories/soft deletes/other conditions are used.
		$this->database->select($this->sort_order_field);
		$this->database->limit(1);
		$this->database->where($this->sort_order_field . ' >', $sort_order);
		//Ascending order so that the lowest sort order of the following rows is returned (the one below it)
		$this->database->order_by($this->sort_order_field . ' ASC');
		$row = $this->database->get($this->_table)->row_array();
		$this->save_query();

		if ($row == NULL) {
			//Can't go down further
			return false;
		}

		$followingRowSortOrder = $row[$this->sort_order_field];


		//Decrease all sort orders to (and including) the following row from before the row which has to be moved
		$this->database->set($this->sort_order_field, $this->sort_order_field . ' - 1', false);
		$this->database->where($this->sort_order_field . ' >', $sort_order);
		$this->database->where($this->sort_order_field . ' <=', $followingRowSortOrder);
		$this->database->update($this->_table);
		$this->save_query();

		//Set the sort order of the moving row to the sort order of the following row
		$this->database->set($this->sort_order_field, $followingRowSortOrder);
		$this->database->where($this->primary_key, $primary_key);
		$this->database->update($this->_table);
		$this->save_query();

		$this->database->trans_complete();

	}

	/*----------------------------------------------------------------------------------------
	 * Filter
	 */

	/**
	 * Filters by the current category ID if one is set and if categories are used
	 *
	 */
	private function filter_category() {
		//Limit to category ID if needed
		if ($this->category_field != NULL && $this->_temporary_category_id != NULL) {
			$this->database->where($this->category_field, $this->_temporary_category_id);
		}
	}

	/**
	 * Filters by the soft delete status if used
	 *
	 */
	private function filter_soft_delete() {
		//Set soft-delete where condition if needed
		if ($this->soft_delete_field != NULL) {
			if ($this->_temporary_only_deleted) {
				$this->database->where($this->soft_delete_field, TRUE);
			} else if (!$this->_temporary_with_deleted) {
				//Only retrieve not deleted records if the with_deleted flag is not set
				$this->database->where($this->soft_delete_field, FALSE);
			}
		}
	}

	/*----------------------------------------------------------------------------------------
	 * Relationships
	 */

	/**
	 * Cascade delete.<br />
	 * Calls delete/undelete on all table models which have been defined as related.
	 *
	 * @param array $rows
	 * @param boolean $delete_undelete TRUE=delete, FALSE=undelete
	 * @return unknown|array/object
	 */
	private function cascade_delete_undelete($rows, $delete_undelete) {

		//Delete/undelete data from each related table
		foreach ($this->cascade_delete as $with_table=>$options) {
			$options = $this->relate_options($with_table, $options);
			$model_name = $options['model'];

			//Loads the model with a special name so that "self" relationships are possible
			//(a model can have a relationship to itself)
			$model = $this->load_related_model($model_name);

			//Array of [local_key=>foreign_key(s)] or simply [foreign_key(s)]
			$related_keys = $options['related_keys'];

			//Modify the related data for each row one by one
			foreach ($rows as $row_key=>$row) {
				$this->related_or_where($row, $with_table, $model_name, $related_keys);
			}

			if ($delete_undelete) {
				$model->delete();
			} else {
				$model->undelete();
			}

			$this->save_related_query($model_name . '_related');
		}

	}

	/*----------------------------------------------------------------------------------------
	 * Tools
	 */

	/**
	 * Associative array diff<br />
	 * From http://php.net/manual/en/function.array-diff-assoc.php#111675
	 *
	 * @param array $array1 The array to compare from
	 * @param array $array2 The array to compare against
	 * @return Returns an array containing all the values from array1 that are not present in the second array.
	 */
	public static function array_diff_assoc_recursive($array1, $array2) {
		$difference=array();
		foreach($array1 as $key => $value) {
			if( is_array($value) ) {
				if( !isset($array2[$key]) || !is_array($array2[$key]) ) {
					$difference[$key] = $value;
				} else {
					$new_diff = self::array_diff_assoc_recursive($value, $array2[$key]);
					if( !empty($new_diff) )
						$difference[$key] = $new_diff;
				}
			} else if( !array_key_exists($key,$array2) || $array2[$key] !== $value ) {
				$difference[$key] = $value;
			}
		}
		return $difference;
	}

}

?>