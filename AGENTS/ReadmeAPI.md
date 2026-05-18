# WebhookForUnity — API Documentation

Серверное PHP-приложение, принимающее запросы из Unity и обеспечивающее аутентификацию студентов, проверку ответов на лабораторные работы и получение статистики.

---

## Содержание

- [Общие сведения](#общие-сведения)
- [Базовый URL и формат запросов](#базовый-url-и-формат-запросов)
- [Параметр `type`](#параметр-type)
- [Эндпоинты](#эндпоинты)
  - [logging — Аутентификация](#logging--аутентификация)
  - [setAnswer — Проверка ответов](#setanswer--проверка-ответов)
  - [getUserStatistic — Статистика пользователя](#getuserstatistic--статистика-пользователя)
  - [getProgress — Прогресс студента](#getprogress--прогресс-студента)
  - [getClasses — Список классов (дашборд учителя)](#getclasses--список-классов-дашборд-учителя)
  - [getClassStudents — Ученики класса](#getclassstudents--ученики-класса)
- [Структура ответов](#структура-ответов)
- [Типы валидации ответов (answers.json)](#типы-валидации-ответов-answersjson)
- [База данных](#база-данных)
- [Логирование](#логирование)
- [Коды ошибок](#коды-ошибок)

---

## Общие сведения

- **Язык:** PHP (без фреймворка)
- **База данных:** SQLite (`App/database/database.sqlite`)
- **Конфигурация лабораторных:** `app/config/answers.json`
- **Все запросы:** `POST` к единственной точке входа `index.php`
- **Формат тела запроса:** `application/x-www-form-urlencoded`
- **Формат ответа:** `application/json` (UTF-8, без экранирования Unicode)

---

## Базовый URL и формат запросов

Все запросы направляются на корневой файл сервера:

```
POST /index.php
Content-Type: application/x-www-form-urlencoded
```

Действие определяется обязательным полем `type` в теле запроса.

---

## Параметр `type`

| Значение | Описание |
|---|---|
| `logging` | Аутентификация пользователя |
| `setAnswer` | Отправка и проверка ответов на лабораторную |
| `getUserStatistic` | Получение статистики конкретного пользователя |
| `getProgress` | Получение прогресса студента по всем лабораторным |
| `getClasses` | Список всех классов (с именем преподавателя) |
| `getClassStudents` | Список учеников класса со средним баллом |

Если передан неизвестный `type`, сервер вернёт `success: false` с сообщением об ошибке.

---

## Эндпоинты

### `logging` — Аутентификация

Проверяет логин и пароль пользователя и возвращает полные данные аккаунта с информацией о лабораторных.

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=logging&login=student01&password=secret
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `logging` |
| `login` | string | да | Логин пользователя |
| `password` | string | да | Пароль (хранится в открытом виде) |

**Успешный ответ (200):**

```json
{
  "success": true,
  "message": "Успешная аутентификация",
  "students": {
    "id": 1,
    "login": "student01",
    "password": "secret",
    "name": "Иван Иванов",
    "age": 20,
    "role": "student",
    "avgMark": 75.50,
    "labs": [
      {
        "labId": 1,
        "name": "Lab 1",
        "methodReadSeconds": 0,
        "videoWatchSeconds": 0,
        "labAvgMark": 80.00,
        "averageMark": 80.00,
        "correctAnswers": 4,
        "totalQuestions": 5,
        "timestamp": "2024-01-15 10:30:00",
        "questions": [
          {
            "questionNumber": 1,
            "answer": "196.67",
            "scorePercent": 100
          }
        ]
      }
    ]
  },
  "error": {
    "isError": false,
    "errorText": "Success"
  }
}
```

**Ошибка — неверный логин:**

```json
{
  "success": false,
  "message": "Неверный логин или пароль",
  "error": {
    "isError": true,
    "errorText": "User"
  }
}
```

**Ошибка — неверный пароль:**

```json
{
  "success": false,
  "message": "Неверный логин или пароль",
  "error": {
    "isError": true,
    "errorText": "Password"
  }
}
```

**Ошибка сервера (500):**

```json
{
  "success": false,
  "message": "Ошибка сервера",
  "error": {
    "isError": true,
    "errorText": "Server"
  }
}
```

---

### `setAnswer` — Проверка ответов

Принимает ответы студента, проверяет их по конфигурации `answers.json`, записывает результат в SQLite и возвращает итог.

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=setAnswer&lab_id=1&id=42&Answer1=196.67&Answer2=26&Answer3=200
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `setAnswer` |
| `lab_id` | integer | да* | Номер лабораторной работы. Алиас: `labNumber` |
| `labNumber` | integer | да* | Алиас для `lab_id` (используется, если `lab_id` не передан) |
| `id` | string | нет | ID студента для записи в базу. По умолчанию `"unknown"` |
| `Answer1..N` | string/number | нет | Ответы студента. Ключи должны начинаться с `Answer` |

\* Требуется одно из двух полей: `lab_id` или `labNumber`. Если оба отсутствуют, используется значение `1`.

**Нормализация числовых значений:**
- Запятая заменяется на точку (`196,67` → `196.67`)
- Пробелы и невидимые символы (Unicode zero-width) удаляются
- Нечисловые строки считаются неверным ответом

**Успешный ответ (200):**

```json
{
  "correct": 3,
  "total": 5,
  "percentage": 60
}
```

| Поле | Тип | Описание |
|---|---|---|
| `correct` | integer | Количество правильных ответов |
| `total` | integer | Общее количество вопросов в лабораторной |
| `percentage` | integer | Процент правильных ответов (0–100, округлено) |

**Ошибка — метод не POST:**

```json
{
  "error": "Method not allowed",
  "correct": 0,
  "total": 0,
  "percentage": 0
}
```

**Ошибка — лабораторная не найдена:**

```json
{
  "error": "Laboratory work not found",
  "correct": 0,
  "total": 0,
  "percentage": 0
}
```

**Побочный эффект:** при каждом вызове создаётся (если не существует) таблица `lab_{id}` в SQLite и записывается строка с ответами студента, общим количеством вопросов, правильными ответами, процентом и временной меткой.

---

### `getUserStatistic` — Статистика пользователя

Возвращает данные пользователя и сводку по его лабораторным работам (последняя попытка по каждой).

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=getUserStatistic&id=42
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `getUserStatistic` |
| `id` | integer | да | ID пользователя |

**Успешный ответ (200):**

```json
{
  "students": {
    "id": 42,
    "name": "Иван Иванов",
    "login": "student01",
    "password": "secret",
    "age": 20,
    "role": "student",
    "avgMark": 75.50,
    "labs": [
      {
        "labId": 1,
        "name": "Lab 1",
        "methodReadSeconds": 0,
        "videoWatchSeconds": 0,
        "labAvgMark": 80.00,
        "averageMark": 80.00,
        "correctAnswers": 4,
        "totalQuestions": 5,
        "timestamp": "2024-01-15 10:30:00",
        "questions": [
          {
            "questionNumber": 1,
            "answer": "196.67",
            "scorePercent": 100
          }
        ]
      }
    ]
  },
  "error": {
    "isError": false,
    "errorText": ""
  }
}
```

**Ошибка — пользователь не найден:**

```json
{
  "students": [],
  "error": {
    "isError": true,
    "errorText": "User not found"
  }
}
```

**Поля объекта `students`:**

| Поле | Тип | Описание |
|---|---|---|
| `id` | integer | ID пользователя |
| `name` | string | Полное имя |
| `login` | string | Логин |
| `password` | string | Пароль (открытый текст) |
| `age` | integer | Возраст |
| `role` | string | Роль: `student` или `teacher` |
| `avgMark` | float | Средний балл по всем лабораторным (0–100) |
| `labs` | array | Массив объектов лабораторных работ |

**Поля объекта лабораторной в `labs`:**

| Поле | Тип | Описание |
|---|---|---|
| `labId` | integer | Номер лабораторной |
| `name` | string | Название лабораторной (из `answers.json` или `"Lab N"`) |
| `methodReadSeconds` | integer | Время чтения методички (в секундах) |
| `videoWatchSeconds` | integer | Время просмотра видео (в секундах) |
| `labAvgMark` | float | Оценка за лабораторную (0–100) |
| `averageMark` | float | Дублирует `labAvgMark` |
| `correctAnswers` | integer | Количество правильных ответов |
| `totalQuestions` | integer | Общее количество вопросов |
| `timestamp` | string | Дата и время последней попытки (`YYYY-MM-DD HH:MM:SS`) |
| `questions` | array | Массив вопросов с ответами студента |

**Поля объекта вопроса в `questions`:**

| Поле | Тип | Описание |
|---|---|---|
| `questionNumber` | integer | Номер вопроса |
| `answer` | string | Ответ студента |
| `scorePercent` | integer | 100 если ответ верен, 0 если нет |

---

### `getProgress` — Прогресс студента

Возвращает все записанные попытки студента по всем лабораторным работам (включая повторные попытки).

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=getProgress&id=42
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `getProgress` |
| `id` | string | да | ID студента |

**Успешный ответ (200):**

```json
{
  "success": true,
  "data": [
    {
      "labId": "lab_1",
      "answers": [
        {
          "id": 1,
          "student_id": "42",
          "Answer1": "196.67",
          "Answer2": "26",
          "total_questions": 5,
          "correct_answers": 3,
          "percentage": 60.0,
          "timestamp": "2024-01-15 10:30:00"
        },
        {
          "id": 2,
          "student_id": "42",
          "Answer1": "196.67",
          "Answer2": "26",
          "total_questions": 5,
          "correct_answers": 5,
          "percentage": 100.0,
          "timestamp": "2024-01-16 09:00:00"
        }
      ]
    },
    {
      "labId": "lab_2",
      "answers": []
    }
  ]
}
```

**Ошибка:**

```json
{
  "success": false,
  "error": "Описание ошибки"
}
```

**Отличие от `getUserStatistic`:** `getProgress` возвращает **все попытки** студента по каждой лабораторной в сыром виде, тогда как `getUserStatistic` возвращает только **последнюю попытку** с агрегированными данными.

---

### `getClasses` — Список классов (дашборд учителя)

Возвращает список всех классов с предметом и именем преподавателя. Соответствует первому экрану дашборда учителя.

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=getClasses
```

Опционально можно передать `teacher_id` для фильтрации классов конкретного преподавателя:

```
type=getClasses&teacher_id=5
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `getClasses` |
| `teacher_id` | integer | нет | Если передан — вернуть только классы этого преподавателя |

**Успешный ответ (200):**

```json
{
  "success": true,
  "classes": [
    {
      "id": 1,
      "name": "11 \"А1\"",
      "subject": "Физика",
      "teacherId": 5,
      "teacherName": "Ключиков Аркадий Викторович"
    },
    {
      "id": 2,
      "name": "10 \"Б2\"",
      "subject": "Математика",
      "teacherId": null,
      "teacherName": ""
    }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `id` | integer | ID класса |
| `name` | string | Название класса (например, `"11 \"А1\""`) |
| `subject` | string | Предмет |
| `teacherId` | integer\|null | ID преподавателя или `null`, если не назначен |
| `teacherName` | string | Полное имя преподавателя или пустая строка |

---

### `getClassStudents` — Ученики класса

Возвращает список учеников указанного класса с их средним баллом. Соответствует второму экрану дашборда учителя. Для просмотра детальной статистики ученика используйте `getUserStatistic`.

**Запрос:**

```
POST /index.php
Content-Type: application/x-www-form-urlencoded

type=getClassStudents&class_id=1
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `type` | string | да | `getClassStudents` |
| `class_id` | integer | да | ID класса |

**Успешный ответ (200):**

```json
{
  "success": true,
  "classId": 1,
  "className": "11 \"А1\"",
  "subject": "Физика",
  "students": [
    {
      "id": 42,
      "name": "Васильев Василий Васильевич",
      "age": 16,
      "login": "vasiliev",
      "avgMark": 75.50
    }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `classId` | integer | ID класса |
| `className` | string | Название класса |
| `subject` | string | Предмет |
| `students` | array | Массив учеников класса |
| `students[].id` | integer | ID студента |
| `students[].name` | string | Полное имя |
| `students[].age` | integer | Возраст |
| `students[].login` | string | Логин |
| `students[].avgMark` | float | Средний балл по всем лабораторным (0–100) |

**Ошибка — класс не найден (404):**

```json
{
  "success": false,
  "error": {
    "isError": true,
    "errorText": "Class not found"
  }
}
```

**Сценарий использования в дашборде:**
1. Вызвать `getClasses` → отобразить карточки классов
2. При клике «УЧЕНИКИ» вызвать `getClassStudents?class_id={id}` → отобразить список учеников
3. При клике «ПРОГРЕСС» вызвать `getUserStatistic?id={student_id}` → показать полную статистику

---

## Структура ответов

### Объект `error`

Присутствует в ответах `logging` и `getUserStatistic`:

```json
{
  "isError": false,
  "errorText": "Success"
}
```

| `errorText` | Описание |
|---|---|
| `"Success"` | Запрос выполнен успешно |
| `"User"` | Пользователь с указанным логином не найден |
| `"Password"` | Неверный пароль |
| `"Server"` | Внутренняя ошибка сервера |
| `"User not found"` | Пользователь с указанным ID не найден |

---

## Типы валидации ответов (answers.json)

Конфигурация лабораторных находится в `app/config/answers.json`. Файл содержит массив объектов — по одному на каждую лабораторную.

**Структура файла:**

```json
[
  {
    "id": 1,
    "name": "Название лабораторной (необязательно)",
    "answers": {
      "Answer1": { ... },
      "Answer2": { ... }
    }
  }
]
```

Ключи ответов должны начинаться с `Answer` (например, `Answer1`, `Answer2`, ...).

---

### `numeric` — Числовое значение с погрешностью

Ответ засчитывается, если `|userValue - correctAnswer| <= limit`.

```json
{
  "type": "numeric",
  "correctAnswer": 196.67,
  "limit": 0.01,
  "description": "Точное числовое значение с погрешностью 0.01"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `correctAnswer` | float | Правильный ответ |
| `limit` | float | Допустимая погрешность |

---

### `exact` — Точное совпадение числа

Ответ засчитывается только при точном равенстве (`userValue == correctAnswer`).

```json
{
  "type": "exact",
  "correctAnswer": 26,
  "limit": 0,
  "description": "Точное целое число"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `correctAnswer` | number | Правильный ответ |
| `limit` | integer | Не используется, указывайте `0` |

---

### `comparison` — Сравнение с пороговым значением

Ответ засчитывается при выполнении условия: `userValue {operator} value`.

```json
{
  "type": "comparison",
  "operator": "<",
  "value": 224,
  "description": "Значение должно быть меньше 224"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `operator` | string | Оператор сравнения: `>`, `<`, `>=`, `<=` |
| `value` | number | Пороговое значение |

---

### `range` — Диапазон значений

Ответ засчитывается, если `min <= userValue <= max`.

```json
{
  "type": "range",
  "min": 7500,
  "max": 7600,
  "description": "Значение должно быть в диапазоне от 7500 до 7600"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `min` | number | Минимальное допустимое значение (включительно) |
| `max` | number | Максимальное допустимое значение (включительно) |

---

### `dependent` — Зависимый ответ

Ответ засчитывается, если он соответствует результату математической операции над другим ответом: `|userValue - (dependentValue {operation} value)| <= limit`.

```json
{
  "type": "dependent",
  "dependsOn": "Answer3",
  "operation": "add",
  "value": 26,
  "limit": 2,
  "description": "Значение должно быть на 26 больше, чем Answer3"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `dependsOn` | string | Ключ ответа, от которого зависит текущий (например, `"Answer3"`) |
| `operation` | string | Операция: `add`, `subtract`, `multiply`, `divide` |
| `value` | number | Значение для операции |
| `limit` | float | Допустимая погрешность |

**Таблица операций:**

| `operation` | Формула |
|---|---|
| `add` | `dependentValue + value` |
| `subtract` | `dependentValue - value` |
| `multiply` | `dependentValue * value` |
| `divide` | `dependentValue / value` |

---

### `multiple_numeric` — Несколько допустимых значений

Ответ засчитывается, если он входит в допуск хотя бы одного из перечисленных правильных значений.

```json
{
  "type": "multiple_numeric",
  "description": "Одно из значений: 0.70 или 0.7",
  "correctAnswers": [
    { "value": 0.70, "limit": 0.02 },
    { "value": 0.7,  "limit": 0.1  }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `correctAnswers` | array | Массив объектов `{ value, limit }` |
| `correctAnswers[].value` | float | Одно из правильных значений |
| `correctAnswers[].limit` | float | Допустимая погрешность для этого значения |

---

## База данных

SQLite-файл: `App/database/database.sqlite`

### Таблица `classes`

Создаётся автоматически при первом обращении к `ClassController` (эндпоинты `getClasses` или `getClassStudents`).

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | ID класса |
| `name` | TEXT NOT NULL | Название класса (например, `"11 А1"`) |
| `subject` | TEXT NOT NULL | Предмет |
| `teacher_id` | INTEGER FK → `users.id` | ID преподавателя (NULL если не назначен) |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | Дата создания |

### Таблица `users`

Создаётся автоматически при первом запросе к `AuthController`. Колонка `class_id` добавляется при первом запросе к `ClassController`.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | ID пользователя |
| `login` | TEXT UNIQUE NOT NULL | Логин |
| `password` | TEXT NOT NULL | Пароль (открытый текст) |
| `name` | TEXT NOT NULL | Полное имя |
| `age` | INTEGER NOT NULL | Возраст |
| `role` | TEXT NOT NULL DEFAULT `'student'` | Роль: `student` или `teacher` |
| `class_id` | INTEGER FK → `classes.id` | Класс студента (NULL для учителей) |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | Дата создания |

### Таблицы `lab_{id}`

Создаются автоматически при первой отправке ответов через `setAnswer`. Каждая лабораторная получает собственную таблицу.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | ID записи |
| `student_id` | TEXT | ID студента (из поля `id` запроса) |
| `Answer1..N` | TEXT | Ответы студента (по числу вопросов в лабораторной) |
| `total_questions` | INTEGER | Общее количество вопросов |
| `correct_answers` | INTEGER | Количество правильных ответов |
| `percentage` | REAL | Процент правильных ответов (0–100) |
| `timestamp` | DATETIME DEFAULT CURRENT_TIMESTAMP | Дата и время попытки |

---

## Логирование

Все входящие запросы и ответы записываются в файл `log.txt` в корне проекта.

**Формат записи:**

```
Timestamp: 2024-01-15 10:30:00
Method: POST
URI: /index.php
StatusCode: 200
RequestBody: type=setAnswer&lab_id=1&id=42&Answer1=196.67
ResponseBody: {"correct":3,"total":5,"percentage":60}
--------------------------------------------------------------------------------
```

При возникновении PHP-ошибки добавляется строка `Error: <сообщение>`.

---

## Коды ошибок

| HTTP-код | Ситуация |
|---|---|
| `200` | Успешный запрос или бизнес-ошибка (неверный логин/пароль) |
| `500` | Внутренняя ошибка сервера (исключение в `AuthController`) |

Большинство ошибок возвращаются со статусом `200`, но с `"success": false` или `"isError": true` в теле ответа.

---

## Пример интеграции из Unity (C#)

```csharp
// Аутентификация
IEnumerator Login(string login, string password)
{
    var form = new WWWForm();
    form.AddField("type", "logging");
    form.AddField("login", login);
    form.AddField("password", password);

    using var request = UnityWebRequest.Post("https://your-server/index.php", form);
    yield return request.SendWebRequest();

    var response = JsonUtility.FromJson<LoginResponse>(request.downloadHandler.text);
    if (response.success) Debug.Log("Вход выполнен: " + response.students.name);
}

// Отправка ответов на лабораторную №1
IEnumerator SubmitAnswers(int studentId, Dictionary<string, string> answers)
{
    var form = new WWWForm();
    form.AddField("type", "setAnswer");
    form.AddField("lab_id", "1");
    form.AddField("id", studentId.ToString());
    foreach (var kv in answers)
        form.AddField(kv.Key, kv.Value); // "Answer1", "Answer2", ...

    using var request = UnityWebRequest.Post("https://your-server/index.php", form);
    yield return request.SendWebRequest();

    var result = JsonUtility.FromJson<AnswerResult>(request.downloadHandler.text);
    Debug.Log($"Правильно: {result.correct}/{result.total} ({result.percentage}%)");
}

// Дашборд учителя — получить все классы (опционально фильтр по teacher_id)
IEnumerator GetClasses(int? teacherId = null)
{
    var form = new WWWForm();
    form.AddField("type", "getClasses");
    if (teacherId.HasValue)
        form.AddField("teacher_id", teacherId.Value.ToString());

    using var request = UnityWebRequest.Post("https://your-server/index.php", form);
    yield return request.SendWebRequest();

    var result = JsonUtility.FromJson<ClassesResponse>(request.downloadHandler.text);
    // result.classes — массив: id, name, subject, teacherId, teacherName
}

// Дашборд учителя — получить учеников класса
IEnumerator GetClassStudents(int classId)
{
    var form = new WWWForm();
    form.AddField("type", "getClassStudents");
    form.AddField("class_id", classId.ToString());

    using var request = UnityWebRequest.Post("https://your-server/index.php", form);
    yield return request.SendWebRequest();

    var result = JsonUtility.FromJson<ClassStudentsResponse>(request.downloadHandler.text);
    // result.students — массив: id, name, age, login, avgMark
}

// Статистика конкретного ученика (вызывается при клике "ПРОГРЕСС")
IEnumerator GetStudentStatistic(int studentId)
{
    var form = new WWWForm();
    form.AddField("type", "getUserStatistic");
    form.AddField("id", studentId.ToString());

    using var request = UnityWebRequest.Post("https://your-server/index.php", form);
    yield return request.SendWebRequest();

    var result = JsonUtility.FromJson<StatisticResponse>(request.downloadHandler.text);
    // result.students.labs — список лабораторных с баллами и ответами
}
```
