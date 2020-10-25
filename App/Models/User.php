<?php

namespace App\Models;

use PDO;
use \App\Token;
use \App\Mail;
use \Core\View;

/**
 * User model
 *
 * PHP version 7.0
 */
class User extends \Core\Model
{

    /**
     * Error messages
     *
     * @var array
     */
    public $errors = [];

    /**
     * Class constructor
     *
     * @param array $data  Initial property values (optional)
     *
     * @return void
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        };
    }

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function save()
    {
        $this->validate();

        if (empty($this->errors)) {

            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $token = new Token();
            $hashed_token = $token->getHash();
            $this->activation_token = $token->getValue();

            $sql = 'INSERT INTO users (name, email, password_hash, activation_hash)
                    VALUES (:name, :email, :password_hash, :activation_hash)';

            $db = static::getDB();
            $stmt = $db->prepare($sql);
			

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
            $stmt->bindValue(':activation_hash', $hashed_token, PDO::PARAM_STR);

            if ($stmt->execute())
			{
				return $this->prepareDefaultCategories();
			}
			else
			{
				return false;
			}
        }

        return false;
    }

    /**
     * Validate current property values, adding valiation error messages to the errors array property
     *
     * @return void
     */
    public function validate()
    {
        // Name
        if ($this->name == '') {
            $this->errors[] = 'Name is required';
        }

        // email address
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = 'Invalid email';
        }
        if (static::emailExists($this->email, $this->id ?? null)) {
            $this->errors[] = 'email already taken';
        }

        // Password
        if (isset($this->password)) {

            if (strlen($this->password) < 6) {
                $this->errors[] = 'Please enter at least 6 characters for the password';
            }

            if (preg_match('/.*[a-z]+.*/i', $this->password) == 0) {
                $this->errors[] = 'Password needs at least one letter';
            }

            if (preg_match('/.*\d+.*/i', $this->password) == 0) {
                $this->errors[] = 'Password needs at least one number';
            }
        }
    }

    /**
     * See if a user record already exists with the specified email
     *
     * @param string $email email address to search for
     * @param string $ignore_id Return false anyway if the record found has this ID
     *
     * @return boolean  True if a record already exists with the specified email, false otherwise
     */
    public static function emailExists($email, $ignore_id = null)
    {
        $user = static::findByEmail($email);

        if ($user) {
            if ($user->id != $ignore_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a user model by email address
     *
     * @param string $email email address to search for
     *
     * @return mixed User object if found, false otherwise
     */
    public static function findByEmail($email)
    {
        $sql = 'SELECT * FROM users WHERE email = :email';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Authenticate a user by email and password. User account has to be active.
     *
     * @param string $email email address
     * @param string $password password
     *
     * @return mixed  The user object or false if authentication fails
     */
    public static function authenticate($email, $password)
    {
        $user = static::findByEmail($email);

        if ($user && $user->is_active) {
            if (password_verify($password, $user->password_hash)) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Find a user model by ID
     *
     * @param string $id The user ID
     *
     * @return mixed User object if found, false otherwise
     */
    public static function findByID($id)
    {
        $sql = 'SELECT * FROM users WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Remember the login by inserting a new unique token into the remembered_logins table
     * for this user record
     *
     * @return boolean  True if the login was remembered successfully, false otherwise
     */
    public function rememberLogin()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->remember_token = $token->getValue();

        $this->expiry_timestamp = time() + 60 * 60 * 24 * 30;  // 30 days from now

        $sql = 'INSERT INTO remembered_logins (token_hash, user_id, expires_at)
                VALUES (:token_hash, :user_id, :expires_at)';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $this->expiry_timestamp), PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Send password reset instructions to the user specified
     *
     * @param string $email The email address
     *
     * @return void
     */
    public static function sendPasswordReset($email)
    {
        $user = static::findByEmail($email);

        if ($user) {

            if ($user->startPasswordReset()) {

                $user->sendPasswordResetEmail();

            }
        }
    }

    /**
     * Start the password reset process by generating a new token and expiry
     *
     * @return void
     */
    protected function startPasswordReset()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->password_reset_token = $token->getValue();

        $expiry_timestamp = time() + 60 * 60 * 2;  // 2 hours from now

        $sql = 'UPDATE users
                SET password_reset_hash = :token_hash,
                    password_reset_expires_at = :expires_at
                WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $expiry_timestamp), PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Send password reset instructions in an email to the user
     *
     * @return void
     */
    protected function sendPasswordResetEmail()
    {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . '/password/reset/' . $this->password_reset_token;

        $text = View::getTemplate('Password/reset_email.txt', ['url' => $url]);
        $html = View::getTemplate('Password/reset_email.html', ['url' => $url]);

        Mail::send($this->email, 'Password reset', $text, $html);
    }

    /**
     * Find a user model by password reset token and expiry
     *
     * @param string $token Password reset token sent to user
     *
     * @return mixed User object if found and the token hasn't expired, null otherwise
     */
    public static function findByPasswordReset($token)
    {
        $token = new Token($token);
        $hashed_token = $token->getHash();

        $sql = 'SELECT * FROM users
                WHERE password_reset_hash = :token_hash';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        $user = $stmt->fetch();

        if ($user) {

            // Check password reset token hasn't expired
            if (strtotime($user->password_reset_expires_at) > time()) {

                return $user;
            }
        }
    }

    /**
     * Reset the password
     *
     * @param string $password The new password
     *
     * @return boolean  True if the password was updated successfully, false otherwise
     */
    public function resetPassword($password)
    {
        $this->password = $password;

        $this->validate();

        if (empty($this->errors)) {

            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $sql = 'UPDATE users
                    SET password_hash = :password_hash,
                        password_reset_hash = NULL,
                        password_reset_expires_at = NULL
                    WHERE id = :id';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);

            return $stmt->execute();
        }

        return false;
    }

    /**
     * Send an email to the user containing the activation link
     *
     * @return void
     */
    public function sendActivationEmail()
    {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . '/signup/activate/' . $this->activation_token;

        $text = View::getTemplate('Signup/activation_email.txt', ['url' => $url]);
        $html = View::getTemplate('Signup/activation_email.html', ['url' => $url]);

        Mail::send($this->email, 'Account activation', $text, $html);
    }

    /**
     * Activate the user account with the specified activation token
     *
     * @param string $value Activation token from the URL
     *
     * @return void
     */
    public static function activate($value)
    {
        $token = new Token($value);
        $hashed_token = $token->getHash();

        $sql = 'UPDATE users
                SET is_active = 1,
                    activation_hash = null
                WHERE activation_hash = :hashed_token';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':hashed_token', $hashed_token, PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * Update the user's profile
     *
     * @param array $data Data from the edit profile form
     *
     * @return boolean  True if the data was updated, false otherwise
     */
    public function updateProfile($data)
    {
        $this->name = $data['name'];
        $this->email = $data['email'];

        // Only validate and update the password if a value provided
        if ($data['password'] != '') {
            $this->password = $data['password'];
        }

        $this->validate();

        if (empty($this->errors)) {

            $sql = 'UPDATE users
                    SET name = :name,
                        email = :email';

            // Add password if it's set
            if (isset($this->password)) {
                $sql .= ', password_hash = :password_hash';
            }

            $sql .= "\nWHERE id = :id";

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

            // Add password if it's set
            if (isset($this->password)) {
                $password_hash = password_hash($this->password, PASSWORD_DEFAULT);
                $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
            }

            return $stmt->execute();
        }

        return false;
    }
	
	/**
     *	Save income to database
     *
     * @return boolean, True when save was successful, otherwise false
     */
    public function saveIncome($data)
    {
		$db = static::getDB();
		
		$sql = "INSERT INTO incomes (id, user_id, income_category_assigned_to_user_id, amount, date_of_income, income_comment) VALUES (NULL, :user_id, :income_category_assigned_to_user_id, :amount, :date_of_income, :income_comment)";
		$stmt = $db->prepare($sql);
		
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		$stmt->bindValue(':income_category_assigned_to_user_id', $data['incomeCategory'], PDO::PARAM_STR);
		$stmt->bindValue(':amount', $data['incomeValue'], PDO::PARAM_INT);
		$stmt->bindValue(':date_of_income', $data['incomeDate'], PDO::PARAM_STR);
		$stmt->bindValue(':income_comment', $data['incomeComment'], PDO::PARAM_INT);
		
		return $stmt->execute();
    }
	
	/**
     *	Save expense to database
     *
     * @return boolean, True when save was successful, otherwise false
     */
    public function saveExpense($data)
    {
		$db = static::getDB();
		
		$sql = "INSERT INTO expenses (id, user_id, expense_category_assigned_to_user_id, payment_method_assigned_to_user_id, amount, date_of_expense, expense_comment) VALUES (NULL, :user_id, :expense_category_assigned_to_user_id, :payment_method_assigned_to_user_id, :amount, :date_of_expense, :expense_comment)";
		$stmt = $db->prepare($sql);
		
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		$stmt->bindValue(':expense_category_assigned_to_user_id', $data['expenseCategory'], PDO::PARAM_STR);
		$stmt->bindValue(':payment_method_assigned_to_user_id', $data['paymentType'], PDO::PARAM_STR);
		$stmt->bindValue(':amount', $data['expenseValue'], PDO::PARAM_INT);
		$stmt->bindValue(':date_of_expense', $data['expenseDate'], PDO::PARAM_STR);
		$stmt->bindValue(':expense_comment', $data['expenseComment'], PDO::PARAM_INT);
		
		return $stmt->execute();
    }
	
	/**
     *	Get incomes categories assigned to user
     *
     * @return array of income categories
     */
    public function getIncomeCategories()
    {
		$db = static::getDB();
		$sql = 'SELECT id, name FROM incomes_category_assigned_to_users WHERE user_id = :user_id';
		
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		
		$stmt->execute();
		return $stmt->fetchAll();
    }
	
	/**
     *	Get expenses categories assigned to user
     *
     * @return array of expense categories
     */
    public function getExpenseCategories()
    {
		$db = static::getDB();
		$sql = 'SELECT id, name FROM expenses_category_assigned_to_users WHERE user_id = :user_id';
		
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		
		$stmt->execute();
		return $stmt->fetchAll();
    }
	
	/**
     *	Get payment types assigned to user
     *
     * @return array of payments category
     */
    public function getPaymentTypes()
    {
		$db = static::getDB();
		$sql = 'SELECT id, name FROM payment_methods_assigned_to_users WHERE user_id = :user_id';
		
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		
		$stmt->execute();
		return $stmt->fetchAll();
    }
	
	/**
     *	Get expenses assigned to user
     *
     * @return array of expenses
     */
    public function getExpenses($startDate, $endDate)
    {
		$db = static::getDB();
		
		$sql = "SELECT expenses_category_assigned_to_users.name, SUM(expenses.amount) FROM expenses INNER JOIN expenses_category_assigned_to_users ON expenses.expense_category_assigned_to_user_id=expenses_category_assigned_to_users.id WHERE expenses.date_of_expense BETWEEN :startDate AND :endDate AND expenses.user_id = :user_id GROUP BY expenses.expense_category_assigned_to_user_id";
		
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		$stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
		$stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
		
		$stmt->execute();
		return $stmt->fetchAll();
    }
	
	/**
     *	Get incomes assigned to user
     *
     * @return array of incomes
     */
    public function getIncomes($startDate, $endDate)
    {
		$db = static::getDB();
		
		$sql = "SELECT incomes_category_assigned_to_users.name, SUM(incomes.amount) FROM incomes INNER JOIN incomes_category_assigned_to_users ON incomes.income_category_assigned_to_user_id=incomes_category_assigned_to_users.id WHERE incomes.date_of_income BETWEEN :startDate AND :endDate AND incomes.user_id = :user_id GROUP BY incomes.income_category_assigned_to_user_id";
		
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':user_id', $this->id, PDO::PARAM_STR);
		$stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
		$stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
		
		$stmt->execute();
		return $stmt->fetchAll();
    }
	
	/**
     *	Get summary for particular period
     *
     * @return number result from defined period
     */
    public function getBalance($startDate, $endDate)
    {
		$db = static::getDB();
		
		$sql = "SELECT IFNULL(TotalIncomes ,0) - IFNULL(TotalExpenses,0) AS balance FROM (SELECT (SELECT SUM(amount) FROM incomes WHERE user_id = :income_user_id and date_of_income and date_of_income BETWEEN :income_startDate and :income_endDate) AS TotalIncomes, (SELECT SUM(amount) FROM expenses WHERE user_id = :expense_user_id and date_of_expense BETWEEN :expense_startDate and :expense_endDate) AS TotalExpenses) temp";
		$stmt= $db->prepare($sql);
		$stmt->bindParam(':income_startDate', $startDate);
		$stmt->bindParam(':income_endDate', $endDate);
		$stmt->bindParam(':income_user_id', $this->id);
		$stmt->bindParam(':expense_startDate', $startDate);
		$stmt->bindParam(':expense_endDate', $endDate);
		$stmt->bindParam(':expense_user_id', $this->id);
		
		$stmt->execute();
		return $stmt->fetch()[0];
    }
	
	/**
     * Copy default categories for incomes, expenses, payments
     *
     * @return @return boolean True if the data was updated, false otherwise
     */
    private function prepareDefaultCategories()
    {
		$db = static::getDB();
		$user = $this->findByEmail($this->email);
		
		$copy_expenses_category_sql = $db->prepare("INSERT INTO incomes_category_assigned_to_users (id, user_id, name) SELECT NULL, :user_id, name FROM incomes_category_default");
		$copy_expenses_category_sql->bindValue(':user_id', $user->id, PDO::PARAM_STR);
		if(!$copy_expenses_category_sql->execute()){return false;}
		
		$copy_incomes_category_sql = $db->prepare("INSERT INTO expenses_category_assigned_to_users (id, user_id, name) SELECT NULL, :user_id, name FROM expenses_category_default");
		$copy_incomes_category_sql->bindValue(':user_id', $user->id, PDO::PARAM_STR);
		if(!$copy_incomes_category_sql->execute()){return false;}
		
		$copy_payment_methods_sql = $db->prepare("INSERT INTO payment_methods_assigned_to_users (id, user_id, name)  SELECT NULL, :user_id, name FROM payment_methods_default");
		$copy_payment_methods_sql->bindValue(':user_id', $user->id, PDO::PARAM_STR);
		if(!$copy_payment_methods_sql->execute()){return false;}
		
		return true;
    }
}