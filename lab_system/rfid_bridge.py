import serial
import requests
import time
import json
import sys

try:
    import mysql.connector
except ImportError:
    mysql = None

SERIAL_PORT = "COM3"
BAUD_RATE = 9600

API_URL = "http://localhost/lab_system/attendance/rfid_api.php"

DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_USER = "root"
DB_PASSWORD = ""
DB_NAME = "lab_system"

SERIAL_TIMEOUT = 1
HTTP_TIMEOUT = 10


def normalize_rfid(rfid):
    rfid = str(rfid).strip().upper()
    rfid = rfid.replace(":", "")
    rfid = rfid.replace("-", "")
    rfid = rfid.replace(" ", "")
    return rfid


def connect_to_arduino():
    print()
    print("=" * 60)
    print("RFID ATTENDANCE BRIDGE")
    print("=" * 60)
    print()
    print("Connecting to Arduino...")
    print("Port:", SERIAL_PORT)
    print("Baud Rate:", BAUD_RATE)

    try:
        arduino = serial.Serial(
            port=SERIAL_PORT,
            baudrate=BAUD_RATE,
            timeout=SERIAL_TIMEOUT,
            write_timeout=3
        )

        time.sleep(2)
        arduino.reset_input_buffer()

        print("Arduino connected successfully.")
        return arduino

    except serial.SerialException as error:
        print()
        print("ERROR: Cannot connect to Arduino.")
        print(error)
        print()
        print("Check:")
        print("1. Arduino is connected.")
        print("2. COM3 is correct.")
        print("3. Arduino Serial Monitor is closed.")
        print("4. No other program is using COM3.")
        return None


def connect_to_database():
    if mysql is None:
        print()
        print("ERROR: mysql-connector-python is not installed.")
        print()
        print("Run this command:")
        print("python -m pip install mysql-connector-python")
        return None

    try:
        db = mysql.connector.connect(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            connection_timeout=5
        )

        if db.is_connected():
            print("MySQL database connected.")
            return db

    except mysql.connector.Error as error:
        print()
        print("DATABASE CONNECTION ERROR:")
        print(error)

    return None


def verify_rfid_in_database(rfid):
    rfid = normalize_rfid(rfid)

    if rfid == "":
        return {
            "verified": False,
            "status": "ERROR",
            "message": "Empty RFID."
        }

    db = connect_to_database()

    if db is None:
        return {
            "verified": False,
            "status": "ERROR",
            "message": "Cannot connect to MySQL."
        }

    cursor = None

    try:
        cursor = db.cursor(dictionary=True)

        query = """
            SELECT
                stud_id,
                student_No,
                first_name,
                middle_Name,
                last_Name,
                extension_name,
                student_RFID_Code
            FROM students
            WHERE REPLACE(
                REPLACE(
                    REPLACE(
                        UPPER(TRIM(student_RFID_Code)),
                        ':',
                        ''
                    ),
                    '-',
                    ''
                ),
                ' ',
                ''
            ) = %s
        """

        cursor.execute(query, (rfid,))
        rows = cursor.fetchall()

        print()
        print("=" * 60)
        print("DIRECT DATABASE VERIFICATION")
        print("=" * 60)
        print("Scanned RFID:", rfid)
        print("Database matches:", len(rows))

        if len(rows) == 0:
            print("DATABASE RESULT: NOT REGISTERED")

            return {
                "verified": False,
                "status": "NOT_REGISTERED",
                "message": "RFID does not exist in students table."
            }

        if len(rows) > 1:
            print("DATABASE RESULT: DUPLICATE RFID")

            return {
                "verified": False,
                "status": "DUPLICATE_RFID",
                "message": "RFID belongs to more than one student."
            }

        student = rows[0]

        database_rfid = normalize_rfid(
            student.get("student_RFID_Code", "")
        )

        student_no = str(
            student.get("student_No", "")
        ).strip()

        first_name = str(
            student.get("first_name", "")
        ).strip()

        middle_name = str(
            student.get("middle_Name", "")
        ).strip()

        last_name = str(
            student.get("last_Name", "")
        ).strip()

        extension_name = str(
            student.get("extension_name", "")
        ).strip()

        name_parts = [
            first_name,
            middle_name,
            last_name,
            extension_name
        ]

        name_parts = [
            part for part in name_parts
            if part
        ]

        student_name = " ".join(name_parts)

        print("Database RFID:", database_rfid)
        print("Student No:", student_no)
        print("Student Name:", student_name)

        if database_rfid != rfid:
            print("DATABASE RESULT: RFID MISMATCH")

            return {
                "verified": False,
                "status": "ERROR",
                "message": "Database RFID mismatch."
            }

        if not student_no:
            print("DATABASE RESULT: STUDENT NUMBER MISSING")

            return {
                "verified": False,
                "status": "ERROR",
                "message": "Registered RFID has no student number."
            }

        if not student_name:
            print("DATABASE RESULT: STUDENT NAME MISSING")

            return {
                "verified": False,
                "status": "ERROR",
                "message": "Registered RFID has no student name."
            }

        print("DATABASE RESULT: REGISTERED")

        return {
            "verified": True,
            "status": "REGISTERED",
            "rfid": database_rfid,
            "student_No": student_no,
            "student_name": student_name
        }

    except mysql.connector.Error as error:
        print()
        print("DATABASE QUERY ERROR:")
        print(error)

        return {
            "verified": False,
            "status": "ERROR",
            "message": str(error)
        }

    finally:
        if cursor is not None:
            cursor.close()

        if db.is_connected():
            db.close()


def send_rfid_to_php(rfid):
    print()
    print("=" * 60)
    print("SENDING VERIFIED RFID TO PHP")
    print("=" * 60)
    print("RFID:", rfid)
    print("API:", API_URL)

    try:
        response = requests.post(
            API_URL,
            data={
                "rfid": rfid
            },
            timeout=HTTP_TIMEOUT
        )

        print("HTTP Status:", response.status_code)
        print()
        print("PHP RAW RESPONSE:")
        print(response.text)

        if response.status_code != 200:
            return {
                "success": False,
                "status": "ERROR",
                "message": "PHP returned HTTP status " + str(response.status_code)
            }

        try:
            result = response.json()
        except ValueError:
            return {
                "success": False,
                "status": "ERROR",
                "message": "PHP returned invalid JSON."
            }

        print()
        print("PHP JSON RESPONSE:")
        print(json.dumps(result, indent=4))

        return result

    except requests.exceptions.ConnectionError as error:
        print()
        print("PHP CONNECTION ERROR:")
        print(error)

        return {
            "success": False,
            "status": "ERROR",
            "message": "Cannot connect to PHP."
        }

    except requests.exceptions.Timeout as error:
        print()
        print("PHP TIMEOUT:")
        print(error)

        return {
            "success": False,
            "status": "ERROR",
            "message": "PHP request timed out."
        }

    except requests.exceptions.RequestException as error:
        print()
        print("HTTP ERROR:")
        print(error)

        return {
            "success": False,
            "status": "ERROR",
            "message": str(error)
        }


def validate_php_result(result, scanned_rfid, database_result):
    print()
    print("=" * 60)
    print("FINAL DATABASE + SYSTEM VALIDATION")
    print("=" * 60)

    if not database_result.get("verified", False):
        print("REJECTED: Direct database verification failed.")
        return "NOT_REGISTERED"

    database_rfid = normalize_rfid(
        database_result.get("rfid", "")
    )

    database_student_no = str(
        database_result.get("student_No", "")
    ).strip()

    database_student_name = str(
        database_result.get("student_name", "")
    ).strip()

    if database_rfid != scanned_rfid:
        print("REJECTED: Scanned RFID does not match database RFID.")
        return "ERROR"

    if not database_student_no:
        print("REJECTED: Database student number is empty.")
        return "ERROR"

    if not database_student_name:
        print("REJECTED: Database student name is empty.")
        return "ERROR"

    if not isinstance(result, dict):
        print("REJECTED: PHP response is invalid.")
        return "ERROR"

    php_success = result.get("success", False)

    php_status = str(
        result.get("status", "")
    ).strip().upper()

    php_rfid = normalize_rfid(
        result.get("rfid", "")
    )

    php_student_no = str(
        result.get("student_No", "")
    ).strip()

    php_student_name = str(
        result.get("student_name", "")
    ).strip()

    print("Scanned RFID       :", scanned_rfid)
    print("Database RFID      :", database_rfid)
    print("Database Student No:", database_student_no)
    print("Database Name      :", database_student_name)
    print("PHP RFID           :", php_rfid)
    print("PHP Student No     :", php_student_no)
    print("PHP Student Name   :", php_student_name)
    print("PHP Success        :", php_success)
    print("PHP Status         :", php_status)

    if php_status == "NOT_REGISTERED":
        print()
        print("REJECTED: PHP says RFID is not registered.")
        return "NOT_REGISTERED"

    if php_status == "DUPLICATE_RFID":
        print()
        print("REJECTED: PHP found duplicate RFID.")
        return "ERROR"

    if php_status not in ["TIME_IN", "COMPLETED"]:
        print()
        print("REJECTED: PHP returned an invalid attendance status.")
        return "ERROR"

    if php_success is not True:
        print()
        print("REJECTED: PHP success is not true.")
        return "ERROR"

    if php_rfid != scanned_rfid:
        print()
        print("REJECTED: PHP RFID does not match scanned RFID.")
        return "ERROR"

    if php_student_no != database_student_no:
        print()
        print("REJECTED: PHP student number does not match database.")
        return "ERROR"

    if not php_student_name:
        print()
        print("REJECTED: PHP student name is empty.")
        return "ERROR"

    print()
    print("DATABASE VERIFICATION: PASSED")
    print("PHP SYSTEM VERIFICATION: PASSED")
    print("RFID MATCH: PASSED")
    print("STUDENT MATCH: PASSED")

    if php_status == "TIME_IN":
        print("ATTENDANCE: NEW RECORD CREATED")
    else:
        print("ATTENDANCE: RECORD ALREADY EXISTS TODAY")

    print()
    print("FINAL DECISION: REGISTERED")

    return "REGISTERED"


def send_response_to_arduino(arduino, decision):
    try:
        if decision == "REGISTERED":
            command = "REGISTERED\n"

        elif decision == "NOT_REGISTERED":
            command = "NOT_REGISTERED\n"

        else:
            command = "ERROR\n"

        print()
        print("Python -> Arduino:", command.strip())

        arduino.write(
            command.encode("ascii")
        )

        arduino.flush()

        print("Arduino response sent successfully.")

        return True

    except serial.SerialTimeoutException as error:
        print()
        print("ARDUINO WRITE TIMEOUT:")
        print(error)
        return False

    except serial.SerialException as error:
        print()
        print("ARDUINO SERIAL ERROR:")
        print(error)
        return False


def process_rfid(arduino, rfid):
    rfid = normalize_rfid(rfid)

    print()
    print("=" * 60)
    print("RFID SCANNED")
    print("=" * 60)
    print("Scanned RFID:", rfid)

    if not rfid:
        print("REJECTED: Empty RFID.")
        send_response_to_arduino(
            arduino,
            "ERROR"
        )
        return

    database_result = verify_rfid_in_database(
        rfid
    )

    if database_result.get("status") == "NOT_REGISTERED":
        print()
        print("DATABASE DOES NOT CONTAIN THIS RFID.")
        print("ACCESS DENIED.")

        send_response_to_arduino(
            arduino,
            "NOT_REGISTERED"
        )

        return

    if database_result.get("status") == "DUPLICATE_RFID":
        print()
        print("DUPLICATE RFID FOUND.")
        print("ACCESS DENIED.")

        send_response_to_arduino(
            arduino,
            "ERROR"
        )

        return

    if not database_result.get("verified", False):
        print()
        print("DATABASE VERIFICATION FAILED.")
        print("ACCESS DENIED.")

        send_response_to_arduino(
            arduino,
            "ERROR"
        )

        return

    verified_rfid = normalize_rfid(
        database_result.get("rfid", "")
    )

    if verified_rfid != rfid:
        print()
        print("RFID MISMATCH.")
        print("ACCESS DENIED.")

        send_response_to_arduino(
            arduino,
            "ERROR"
        )

        return

    php_result = send_rfid_to_php(
        rfid
    )

    decision = validate_php_result(
        php_result,
        rfid,
        database_result
    )

    print()
    print("=" * 60)
    print("FINAL ATTENDANCE DECISION")
    print("=" * 60)
    print("RFID:", rfid)
    print("Decision:", decision)

    send_response_to_arduino(
        arduino,
        decision
    )

    print()


def listen_for_rfid(arduino):
    print()
    print("=" * 60)
    print("SYSTEM READY")
    print("=" * 60)
    print()
    print("Waiting for RFID...")
    print("Press CTRL+C to stop.")
    print()

    while True:
        try:
            raw_data = arduino.readline()

            if not raw_data:
                continue

            line = raw_data.decode(
                "utf-8",
                errors="ignore"
            ).strip()

            if not line:
                continue

            print("Arduino:", line)

            if line.upper().startswith("RFID:"):
                rfid = line[
                    len("RFID:"):
                ].strip()

                process_rfid(
                    arduino,
                    rfid
                )

                print("Waiting for next RFID...")
                print()

        except KeyboardInterrupt:
            print()
            print("RFID bridge stopped.")
            break

        except serial.SerialException as error:
            print()
            print("SERIAL ERROR:")
            print(error)
            break

        except Exception as error:
            print()
            print("UNEXPECTED ERROR:")
            print(error)
            time.sleep(1)


def main():
    arduino = connect_to_arduino()

    if arduino is None:
        input(
            "Press Enter to exit..."
        )
        sys.exit(1)

    try:
        listen_for_rfid(
            arduino
        )

    finally:
        if arduino.is_open:
            arduino.close()

        print()
        print("Arduino connection closed.")


if __name__ == "__main__":
    main()