# تقرير تفصيلي: صفحة AI - Diagnosis Test
## `http://127.0.0.1:8000/admin/ai-diagnosis-test`

---

## 1. نظرة عامة

صفحة **AI - Diagnosis Test** هي واجهة اختبار التشخيص بالذكاء الاصطناعي. تتيح للمستخدم تشغيل **استدلال (Inference)** على شريحة مرضية باستخدام نموذج CLAM تم تدريبه مسبقاً، وعرض النتائج (التوقع، الثقة، خريطة الاهتمام).

**التدفق العام:**
```
نموذج مدرب (TrainingRun) → شريحة مرضية → RunPod GPU Server → نتيجة التشخيص
```

---

## 2. الروابط (Routes)

### Routes الويب (Admin)

| الطريقة | المسار | المُ_contrller@الدالة | اسم الرابط |
|---------|--------|----------------------|-----------|
| `GET` | `admin/ai-diagnosis-test` | `InferenceController@index` | `admin.ai-diagnosis-test` |
| `POST` | `admin/ai-diagnosis-test/dispatch` | `InferenceController@dispatch` | `admin.ai-diagnosis-test.dispatch` |
| `GET` | `admin/ai-diagnosis-test/samples-for-run/{trainingRun}` | `InferenceController@samplesForRun` | `admin.ai-diagnosis-test.samples-for-run` |
| `GET` | `admin/ai-diagnosis-test/{inferenceRun}/status` | `InferenceController@status` | `admin.ai-diagnosis-test.status` |

### Routes الـ API (Callbacks من RunPod)

| الطريقة | المسار | المُ_contrller@الدالة | اسم الرابط |
|---------|--------|----------------------|-----------|
| `POST` | `api/v1/inference/report` | `InferenceApiController@report` | `api.inference.report` |
| `POST` | `api/v1/inference/progress` | `InferenceApiController@progress` | `api.inference.progress` |

**Middleware:** `auth` للـ Web routes، `verify.server.api_key` للـ API routes

---

## 3. هيكل الصفحة (3 خطوات + سجل)

### الخطوة 1: اختيار النموذج المدرب

**الملف:** `ai-diagnosis-test.blade.php` (سطر 46-169)

**الوظيفة:** اختيار Training Run مكتمل سيُستخدم checkpoint الخاص به للاستدلال.

**المكونات:**
- **قائمة منسدلة** (`training_run_id`) — جميع الـ Training Runs المكتملة التي لها `model_gdrive_path`
  - كل خيار يحمل data-* attributes للـ JavaScript:
    - `data-head` — اسم نموذج التصنيف (Training Head)
    - `data-feature` — نموذج استخراج الميزات
    - `data-type` — نوع المعمارية (CLAM_SB/CLAM_MB)
    - `data-classes` — عدد الفئات
    - `data-auc` — أفضل AUC من المetrics
    - `data-checkpoint` — مسار checkpoint على GDrive
    - `data-labelmap` — خريطة التسميات (JSON)

- **لوحة تفاصيل النموذج** (`modelDetailsPanel`) — تظهر عند اختيار Training Run:
  - Head (نموذج التصنيف)
  - Arch (المعمارية)
  - Features (نموذج الميزات)
  - Classes (عدد الفئات)
  - Best AUC
  - Label Map

- **خادم الاستدلال** (`server_id`) — قائمة بجميع الخوالم النشطة

---

### الخطوة 2: اختيار الشريحة المرضية

**الملف:** `ai-diagnosis-test.blade.php` (سطر 171-318)

**تبويبان (Tabs):**

#### التبويب 1: "From DB Samples"

- جدول عينات يُحمّل عبر **AJAX** عند تغيير Training Run
- فقط العينات التي:
  - `feature_extraction_status = completed`
  - `features_gdrive_path` موجود
  - `feature_extraction_ai_model_id` يطابق `feature_model_id` للـ Training Run
- أعمدة الجدول: تحديد (radio)، #، اسم الملف، الفئة، مسار الميزات على GDrive
- **اختيار واحد فقط** (radio buttons)
- مربع مخفي (`selectedSampleId`) يحفظ المعرف المحدد

#### التبويب 2: "GDrive Features Path"

- للشريحة **غير الموجودة** في قاعدة البيانات
- حقل **Slide Name** — اسم عرضي للشريحة
- حقل **GDrive Features Path** — مسار ملف `.h5` على Google Drive
- المسار يكون نسبياً من جذر GDrive المُعد في rclone

**ملاحظة:** تبديل التبويبين يُحدّث حقل `slide_source` المخفي (`sample` أو `gdrive`)

---

### الخطوة 3: التنفيذ

**الملف:** `ai-diagnosis-test.blade.php` (سطر 320-346)

- زر **"Run AI Diagnosis Test"** — يُرسل النموذج إلى `dispatch`
- قبل الإرسال، يتحقق JavaScript من:
  - إذا كان `slide_source = sample`، يجب تحديد عينة
- الزر يتحول إلى "Dispatching..." مع spinner بعد الإرسال

---

### جدول السجل (History)

**الملف:** `ai-diagnosis-test.blade.php` (سطر 348-500)

- **صفحات** من 20 نتيجة
- أعمدة الجدول:

| العمود | الوصف |
|--------|-------|
| `#` | معرف Inference Run |
| `Training Run` | معرف + نموذج التصنيف + نموذج الميزات |
| `Slide` | اسم الشريحة + معرف العينة (إن وُجد) |
| `Source` | `DB` أو `GDrive` |
| `Status` | `pending` / `processing` / `completed` / `failed` |
| `Prediction` | اسم الفئة المتوقعة (أخضر إذا الفئة 0، أحمر إذا غير ذلك) |
| `Confidence` | شريط تقدم + نسبة مئوية |
| `GDrive Output` | مسار مجلد الإخراج |
| `Date` | تاريخ الإنشاء |
| زر العين | فتح modal التفاصيل (للحالات المكتملة/Failed) |
| زر التحديث | polling للحالات قيد التنفيذ |

---

### Modals التفاصيل

**الملف:** `ai-diagnosis-test.blade.php` (سطر 506-594)

لكل Inference Run مكتمل أو فاشل، يُنشأ Modal يعرض:

1. **نتيجة التوقع** — اسم الفئة بخط كبير (أخضر/أحمر)
2. **الثقة** — النسبة المئوية
3. **رسم بياني لأحتمالات الفئات** — progress bars لكل فئة
4. **خريطة الاهتمام (Attention Map)** — مسار GDrive إن وُجد
5. **بيانات وصفية** — Training Run، Head، Feature Model، GDrive Output، Finished At
6. **رسالة الخطأ** — إذا كان الـ status = failed

---

## 4. الـ Controller: InferenceController

**الملف:** `app/Http/Controllers/Admin/InferenceController.php` (185 سطر)

### index() — عرض الصفحة

**الاستعلامات:**
1. `TrainingRun` — مكتملة فقط (`status = completed` و `model_gdrive_path` موجود)
   - مع eager loading لـ `trainingHead` و `featureModel`
2. `ServerName` — نشطة فقط
3. `Sample` — مؤهلة فقط (`feature_extraction_status = completed` و `features_gdrive_path` موجود)
   - مع eager loading لـ `category` و `organ`
4. `InferenceRun` — جميع السجلات (للسجل)
   - مع eager loading لـ `trainingRun` (مع `trainingHead` و `featureModel`) و `sample`
   - pagination بـ 20 نتيجة

**المتغيرات المُمررة للـ View:**
- `trainingRuns`, `servers`, `eligibleSamples`, `selectedRunId`, `history`

### dispatch() — إرسال مهمة الاستدلال

**التحقق من المدخلات:**

| المعلمة | القواعد |
|---------|--------|
| `training_run_id` | مطلوب، صحيح، موجود في `training_runs` |
| `server_id` | مطلوب، صحيح، موجود في `servers_names` |
| `slide_source` | مطلوب، `sample` أو `gdrive` |
| `sample_id` | مطلوب إذا `slide_source=sample`، موجود في `samples` |
| `slide_name` | مطلوب إذا `slide_source=gdrive`، أقصى 500 حرف |
| `slide_features_gdrive_path` | مطلوب إذا `slide_source=gdrive`، أقصى 1000 حرف |

**التحقق الإضافي:**
1. الـ Training Run يجب أن يكون مكتملاً ولديه `model_gdrive_path`
2. إذا كان `slide_source = sample`:
   - العينة يجب أن يكون `feature_extraction_status = completed` و `features_gdrive_path` موجود
   - **مطابقة نموذج الميزات:** `sample.feature_extraction_ai_model_id` يجب أن يطابق `trainingRun.feature_model_id`

**خطوات التنفيذ:**
1. إنشاء سجل `InferenceRun` مع `status = pending`
2. تعيين `gdrive_output_dir` تلقائياً: `inference/results/run_{id}`
3. إرسال `InferenceJob` إلى الـ Queue
4. إعادة توجيه مع رسالة نجاح

### samplesForRun() — AJAX لجلب العينات المؤهلة

- يأخذ `TrainingRun` (route model binding)
- يُرجع JSON:
  ```json
  {
    "samples": [...],
    "feature_model": "TITAN",
    "label_map": {"0": "Normal", "1": "Malignant"},
    "n_classes": 2,
    "model_type": "clam_sb"
  }
  ```
- الفلتر: `feature_extraction_status = completed` و `features_gdrive_path` موجود و `feature_extraction_ai_model_id` يطابق `feature_model_id`

### status() — AJAX للاستعلام عن الحالة

- يأخذ `InferenceRun` (route model binding)
- يُرجع JSON:
  ```json
  {
    "id": 1,
    "status": "completed",
    "prediction": {...},
    "error": null,
    "finished_at": "2026-06-01 14:30:00"
  }
  ```

---

## 5. الـ Controller: InferenceApiController (API Callbacks)

**الملف:** `app/Http/Controllers/Api/V1\InferenceApiController.php` (103 سطر)

### report() — استقبال النتيجة النهائية

**البيانات المتوقعة من RunPod:**
```json
{
  "inference_run_id": 1,
  "status": "completed",
  "prediction": {
    "class_label": "Normal",
    "class_index": 0,
    "confidence": 0.923,
    "probabilities": { "0": 0.923, "1": 0.077 }
  },
  "attention_map_path": "inference/results/run_1/attention.png",
  "error": null
}
```

**التحديثات:**
- `status` → `completed` أو `failed`
- `prediction` → JSON بالنتيجة
- `attention_map_gdrive_path` → مسار خريطة الاهتمام
- `error` → رسالة الخطأ (إن وُجد)
- `finished_at` → `now()`

### progress() — تحديث التقدم intermediari

**البيانات المتوقعة:**
```json
{
  "inference_run_id": 1,
  "stage": "loading_features",
  "message": "Loading .h5 features file from GDrive..."
}
```

**السلوكيات:**
- يُحوّل `status` من `pending` إلى `processing` عند أول ping
- يُسجّل في Log

---

## 6. المهمة الخلفية: InferenceJob

**الملف:** `app/Jobs/InferenceJob.php` (88 سطر)

**الخصائص:**
- `ShouldQueue` — تُنفذ في الخلفية
- `timeout = 120` — 120 ثانية (لأن الاستدلال الفعلي على RunPod غير متزامن)
- `tries = 2` — محاولتان

**المنطق:**

1. تحميل `InferenceRun` مع eager loading لـ `trainingRun.server`, `trainingRun.trainingHead`, `trainingRun.featureModel`, `server`
2. تحديد الخادم:
   - خادم الـ Inference Run الخاص (`$run->server`)
   - أو خادم الـ Training Run كـ fallback
3. التحقق من وجود `api_url` على الخادم
4. بناء الـ payload:
   ```php
   [
       'inference_run_id'      => $run->id,
       'model_checkpoint_path' => $trainingRun->model_gdrive_path,
       'feature_model'         => 'TITAN',
       'slide_features_path'   => $run->slide_features_gdrive_path,
       'slide_name'            => $run->slide_name,
       'n_classes'             => 2,
       'model_type'            => 'clam_sb',
       'label_map'             => ["0" => "Normal", "1" => "Malignant"],
       'gdrive_output_dir'     => "inference/results/run_1",
   ]
   ```
5. تحديث الحالة إلى `processing` مع `started_at`
6. إرسال HTTP POST إلى `{server->api_url}/inference/start` مع Bearer token
7. عند الخطأ: تحديث `status` إلى `failed` مع رسالة الخطأ

---

## 7. النماذج (Models)

### InferenceRun

**الملف:** `app/Models/InferenceRun.php` (78 سطر)
**الجدول:** `inference_runs`

| الحقل | النوع | الوصف |
|-------|-------|-------|
| `training_run_id` | FK → `training_runs` | النموذج المدرب المستخدم |
| `server_id` | FK → `servers_names` (nullable) | خادم التنفيذ |
| `slide_source` | enum: `sample`, `gdrive` | مصدر الشريحة |
| `sample_id` | FK → `samples` (nullable) | معرف العينة (إذا من DB) |
| `slide_name` | string (nullable) | اسم الشريحة |
| `slide_features_gdrive_path` | string (nullable) | مسار ملف الميزات على GDrive |
| `status` | enum: `pending`, `processing`, `completed`, `failed` | حالة التنفيذ |
| `prediction` | JSON (nullable) | نتيجة التوقع |
| `attention_map_gdrive_path` | string (nullable) | مسار خريطة الاهتمام |
| `gdrive_output_dir` | string (nullable) | مجلد الإخراج على GDrive |
| `error` | text (nullable) | رسالة الخطأ |
| `started_at` | timestamp (nullable) | بداية التنفيذ |
| `finished_at` | timestamp (nullable) | انتهاء التنفيذ |

**العلاقات:**
- `trainingRun()` → BelongsTo TrainingRun
- `server()` → BelongsTo ServerName
- `sample()` → BelongsTo Sample

**الـ Accessors:**
- `predicted_label` — اسم الفئة المتوقعة
- `confidence` — درجة الثقة (0-1)
- `confidence_percent` — النسبة المئوية (مثل "92.3%")
- `status_badge_class` — كلاس Bootstrap للـ badge

### TrainingRun

**الملف:** `app/Models/TrainingRun.php` (69 سطر)
**الجدول:** `training_runs`

**الحقول المستخدمة في هذه الصفحة:**
- `model_gdrive_path` — مسار checkpoint النموذج المدرب
- `feature_model_id` — معرف نموذج الميزات
- `n_classes` — عدد الفئات
- `model_type` — نوع المعمارية
- `label_map` — خريطة التسميات (JSON)
- `metrics` — مقاييس الأداء (JSON، يحتوي على `best_val_auc`)

**العلاقات:**
- `trainingHead()` → BelongsTo AiModel (via `training_head_id`)
- `featureModel()` → BelongsTo AiModel (via `feature_model_id`)
- `server()` → BelongsTo ServerName
- `samples()` → BelongsToMany Sample

---

## 8. قاعدة البيانات: جدول inference_runs

**الملف:** `database/migrations/2026_06_02_000001_create_inference_runs_table.php`

```sql
CREATE TABLE inference_runs (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    training_run_id       BIGINT UNSIGNED NOT NULL,
    server_id             BIGINT UNSIGNED NULL,
    slide_source          ENUM('sample', 'gdrive') DEFAULT 'sample',
    sample_id             BIGINT UNSIGNED NULL,
    slide_name            VARCHAR(255) NULL,
    slide_features_gdrive_path VARCHAR(1000) NULL,
    status                ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    prediction            JSON NULL,
    attention_map_gdrive_path VARCHAR(1000) NULL,
    gdrive_output_dir     VARCHAR(255) NULL,
    error                 TEXT NULL,
    started_at            TIMESTAMP NULL,
    finished_at           TIMESTAMP NULL,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,

    FOREIGN KEY (training_run_id) REFERENCES training_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers_names(id) ON DELETE SET NULL,
    FOREIGN KEY (sample_id) REFERENCES samples(id) ON DELETE SET NULL
);
```

---

## 9. واجهة المستخدم JavaScript

**الملف:** `ai-diagnosis-test.blade.php` (سطر 597-788)

### الوظائف الرئيسية:

| الوظيفة | المسؤولية |
|---------|----------|
| `runSelect` change handler | تحديث لوحة تفاصيل النموذج + تحميل العينات عبر AJAX |
| `loadSamplesForRun(runId)` | جلب العينات المؤهلة من الخادم وملء الجدول |
| `resetSamplesTable()` | إعادة تعيين حالة الجدول |
| Radio click handler | تحديث `selectedSampleId` المخفي |
| Tab switch handler | تحديث `slideSourceInput` المخفي (`sample`/`gdrive`) |
| Form submit validation | التحقق من تحديد عينة في وضع DB |
| `pollStatus(runId)` | AJAX polling لحالة Inference Run + إعادة تحميل عند الانتهاء |
| `escHtml(str)` | تنظيف النص من XSS |

### تدفق AJAX:

```
1. المستخدم يختار Training Run
   ↓
2. JavaScript يحدث لوحة التفاصيل (data-* attributes)
   ↓
3. JavaScript يُرسل GET /samples-for-run/{id}
   ↓
4. الخادم يُرجع JSON بالعينات المؤهلة
   ↓
5. JavaScript يملأ الجدول ديناميكياً
   ↓
6. المستخدم يحدد عينة (radio) + يضغط "Run"
   ↓
7. POST /dispatch → InferenceJob → RunPod
   ↓
8. RunPod يُنجز → POST /api/v1/inference/report
   ↓
9. المستخدم يضغط زر التحديث → pollStatus() → reload
```

---

## 10. تدفق العمل الكامل (End-to-End)

```
┌─────────────────────────────────────────────────────────┐
│  المستخدم يفتح الصفحة                                   │
│  GET /admin/ai-diagnosis-test                           │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  الخطوة 1: اختيار Training Run + Server                 │
│  - قائمة المكتملة فقط (completed + model_gdrive_path)   │
│  - لوحة تفاصيل النموذج (Head, Arch, AUC, Label Map)    │
│  - اختيار خادم الاستدلال                                │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  الخطوة 2: اختيار الشريحة                               │
│  ┌──────────────────┐  ┌──────────────────────┐        │
│  │ DB Samples (AJAX)│  │ GDrive Path (يدوي)   │        │
│  │ radio selection   │  │ slide_name + path     │        │
│  └──────────────────┘  └──────────────────────┘        │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  الخطوة 3: تنفيذ                                         │
│  POST /dispatch → Validation → Create InferenceRun      │
│  → Dispatch InferenceJob                                │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  InferenceJob (Background Queue)                        │
│  1. تحميل InferenceRun + relations                     │
│  2. بناء payload (checkpoint, features, config)         │
│  3. POST {server}/api/inference/start                   │
│  4. الحالة → processing                                 │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  RunPod GPU Server (خارجياً)                            │
│  1. تحميل النموذج من checkpoint                         │
│  2. تحميل ميزات الشريحة (.h5)                          │
│  3. تشغيل الاستدلال                                     │
│  4. POST /api/v1/inference/progress (اختياري)           │
│  5. POST /api/v1/inference/report (النتيجة النهائية)    │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  InferenceApiController::report()                       │
│  - تحديث status → completed/failed                      │
│  - حفظ prediction (class_label, confidence, probs)      │
│  - حفظ attention_map_gdrive_path                        │
│  - تعيين finished_at                                    │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  المستخدم يرى النتيجة                                   │
│  - Status badge يتغير                                   │
│  - pollStatus() → reload → Modal التفاصيل               │
│  - Prediction + Confidence bar + Class probabilities    │
│  - Attention map path                                   │
└─────────────────────────────────────────────────────────┘
```

---

## 11. الحماية والتحقق

| نوع التحقق | الموقع | الوصف |
|------------|--------|-------|
| Authentication | `routes/web.php` | middleware `auth` |
| API Authentication | `routes/api.php` | middleware `verify.server.api_key` (Bearer token) |
| CSRF | `bootstrap/app.php` | مُستثنى لـ `api/*` فقط |
| Training Run Status | `InferenceController:90-92` | يجب أن يكون `completed` وله `model_gdrive_path` |
| Feature Model Match | `InferenceController:108-113` | نموذج الميزات يجب أن يطابق بين العينة والـ Training Run |
| Sample Eligibility | `InferenceController:103-105` | `feature_extraction_status = completed` و `features_gdrive_path` موجود |
| Client-side Validation | `ai-diagnosis-test.blade.php:744-754` | التأكد من تحديد عينة في وضع DB |
| Form Disable | `ai-diagnosis-test.blade.php:751-753` | تعطيل الزر بعد الإرسال لمنع التكرار |
| API Input Validation | `InferenceApiController:39-48` | التحقق من جميع حقول الـ callback |

---

## 12. ملخص الملفات

```
routes/web.php                                    → 4 routes للـ admin
routes/api.php                                    → 2 routes للـ API callbacks
app/Http/Controllers/Admin/InferenceController.php → الـ controller الرئيسي (185 سطر)
app/Http/Controllers/Api/V1/InferenceApiController.php → callbacks من RunPod (103 سطر)
app/Jobs/InferenceJob.php                         → المهمة الخلفية (88 سطر)
app/Models/InferenceRun.php                       → النموذج الرئيسي (78 سطر)
app/Models/TrainingRun.php                        → نموذج التدريب (69 سطر)
app/Models/Sample.php                             → نموذج العينة (198 سطر)
app/Models/ServerName.php                         → نموذج الخادم (61 سطر)
app/Models/AiModel.php                            → نموذج الذكاء الاصطناعي (58 سطر)
app/Http/Middleware/VerifyServerApiKey.php         → متوسط مصادقة الـ API (64 سطر)
resources/views/admin/ai-diagnosis-test.blade.php → الواجهة (789 سطر)
resources/views/admin/layouts/app.blade.php       → التخطيط الرئيسي
resources/views/admin/partials/sidebar.blade.php  → الشريط الجانبي
database/migrations/...create_inference_runs_table.php → جدول inference_runs
bootstrap/app.php                                 → تسجيل الـ middleware
```

---

*تم إنشاء هذا التقرير تلقائياً من تحليل الكود المصدري.*
