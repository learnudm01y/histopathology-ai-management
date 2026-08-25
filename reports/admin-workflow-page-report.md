# تقرير تفصيلي: صفحة Operations (Workflow)
## `http://127.0.0.1:8000/admin/workflow`

---

## 1. نظرة عامة

صفحة **Operations** هي البوابة المركزية لإدارة أنابيب معالجة الصور المرضية (Histopathology Pipeline). تتيح للمستخدم تنفيذ ثلاث عمليات رئيسية على مجموعات من الشرائح المرضية (WSI Slides):

1. **Patch Extraction** — تقطيع الشرائح إلى قطع صغيرة (Tiles)
2. **Feature Extraction** — استخراج الميزات باستخدام نموذج ذكاء اصطناعي على خادم GPU
3. **Model Training** — تدريب نموذج تصنيف CLAM على الميزات المستخرجة

> عملية **Inference** موجودة في الواجهة لكنها معطلة (`disabled`) وتحت التطوير.

---

## 2. الروابط (Routes)

| الطريقة | المسار | المُ_contrller@الدالة | اسم الرابط |
|---------|--------|----------------------|-----------|
| `GET` | `admin/workflow` | `DashboardController@workflow` | `admin.workflow` |
| `POST` | `admin/workflow/dispatch/patch-extraction` | `OperationsController@dispatchPatchExtraction` | `admin.workflow.dispatch.patch-extraction` |
| `POST` | `admin/workflow/dispatch/feature-extraction` | `OperationsController@dispatchFeatureExtraction` | `admin.workflow.dispatch.feature-extraction` |
| `POST` | `admin/workflow/dispatch/training` | `OperationsController@dispatchTraining` | `admin.workflow.dispatch.training` |

**Middleware:** `auth` (يجب تسجيل الدخول للوصول)

---

## 3. هيكل الصفحة (4 خطوات)

الصفحة مصممة كـ **مساعد خطوات (Wizard)** من 4 مراحل:

### الخطوة 1: اختيار نوع العملية

**الملف:** `workflow.blade.php` (سطر 38-78)

- قائمة منسدلة واحدة (`operationTypeSelect`) تحدد نوع العملية
- الخيارات المتاحة:
  - `patch_extraction` — تقطيع الشرائح
  - `feature_extraction` — استخراج الميزات ( عبر RunPod)
  - `training` — تدريب نموذج CLAM
  - `inference` — (معطل، قادم قريباً)
- تغيير القائمة يُظهر/يُخفي الأقسام ذات الصلة عبر JavaScript

---

### الخطوة 2: تكوين التنفيذ (تختلف حسب العملية)

#### 2أ. Patch Extraction — اختيار الخادم والتكوين

**الملف:** `workflow.blade.php` (سطر 536-648)

 THREE dropdowns:
- **خادم التنفيذ** (`serverSelect`) — قائمة بجميع الخوالم النشطة من جدول `servers_names`
- **حجم القطعة** (`patchSizeSelectStep2`) — قائمة بجميع أحجام القطع النشطة من جدول `patch_sizes`
- **التكبير** (`magnificationSelectStep2`) — قائمة بجميع مستويات التكبير النشطة من جدول `magnifications`
- زر **"Confirm & Load Sample Filters"** — يُعيد تحميل الصفحة مع المعلمات في URL

#### 2ب. Feature Extraction — اختيار الخادم والنموذج

**الملف:** `workflow.blade.php` (سطر 80-232)

- **خادم GPU الخارجي** (`feSelectServer`) — فقط الخوالم من نوع `external`
- **نموذج الذكاء الاصطناعي** (`feSelectModel`) — جميع النماذج النشطة، الافتراضي محدد مسبقاً
- **جدول العينات المؤهلة** — عينات具有 `tiling_status = done` ومسار GDrive موجود
  - يعرض: الملف، الحالة، عدد القطع، التكبير، حجم القطعة، حالة استخراج الميزات
  - حد أقصى 200 عينة
- زر **"Dispatch to RunPod"** — مع عداد العينات المحددة

#### 2ج. Training — نموذج تدريب CLAM الكامل

**الملف:** `workflow.blade.php` (سطر 234-534)

**معلمات النموذج:**

| المعلمة | المدخل | الوصف |
|---------|--------|-------|
| Training Server | `trSelectServer` | خادم التنفيذ (نوع `external`) |
| Training Head | `trSelectHead` | نموذج التصنيف (CLAM) من نوع `classification` |
| Feature Model | `trSelectFeat` | النموذج المستخدم لاستخراج الميزات (`foundation`/`multimodal`/`other`) |
| Architecture | `trModelType` | `clam_sb` (فرع واحد) أو `clam_mb` (عدة فروع) |
| Label Source | `trLabelType` | `category` أو `disease_type` |
| Epochs | `trEpochs` | عدد العصور (1-200، افتراضي: 20) |
| Learning Rate | `trLR` | معدل التعلم (0.000001-0.1، افتراضي: 0.0001) |
| Bag Size | `trBagSize` | حجم الكيسة (-1 = جميع القطع) |
| Num Classes | `trNClasses` | عدد الفئات (2-10، افتراضي: 2) |
| GDrive Output | `trGDriveOut` | مجلد الإخراج على GDrive (تلقائي إذا فارغ) |

**مُنشئ خريطة التسميات (Label Map Builder):**
- واجهة ديناميكية لإضافة/حذف صفوف
- كل صف يُعيّن رقم فئة (0, 1, 2, ...) إلى اسم بشري
- يُسلّم كـ JSON مخفي (`labelMapJson`)

**جدول العينات المؤهلة:**
- عينات具有 `feature_extraction_status = completed` ومسار GDrive موجود
- حد أقصى 500 عينة
- يعرض: الملف، الحالة، الفئة، نوع المرض، نموذج الميزات، مسار الميزات
- **أعمدة التجزئة (Split)** — أزرار Train/Val/Test لكل عينة محددة

**ملخص التجزئة (Split Summary Bar):**
- يعرض عدادات: Train, Val, Test, Unassigned
- أزرار bulk assignment: "All → Train", "All → Val", "All → Test"
- كشف تسرب البيانات (Data Leakage): إذا عينتان من نفس الحالة في تجزئتين مختلفتين

---

### الخطوة 3: فلاتر العينات

**الملف:** `workflow.blade.php` (سطر 650-844)

فقط تظهر عندما يكون `operation_type = patch_extraction` والخادم مؤكد.

| الفلتر | الحقل | القيم |
|--------|-------|-------|
| Unique per case | `uniqueness` | `any` / `unique` (عينة واحدة لكل حالة) |
| Gender | `gender` | `male` / `female` |
| Image Quality | `quality_status` | `passed` / `rejected` / `needs_review` / `pending` |
| Category | `category_id` | قائمة الفئات من جدول `categories` |
| Min Size (GB) | `min_size_gb` | رقم عشري |
| Max Size (GB) | `max_size_gb` | رقم عشري |
| Organ | `organ_id` | قائمة الأعضاء من جدول `organs` |
| Stain | `stain_id` | قائمة الصبغات من جدول `stains` |
| Data Source | `data_source_id` | قائمة مصادر البيانات من جدول `data_sources` |
| Disease Type | `disease_type` | قائمة أنواع الأمراض من جدول `cases` |
| Tiling Status | `tiling_status` | `pending` / `processing` / `done` / `failed` |
| Magnification | `filter_magnification_id` | قائمة التكبيرات النشطة |
| Usable | `is_usable` | `1` (صالح) / `0` (غير صالح) |

**أزرار:** "Apply Filters" و "Reset All"

---

### الخطوة 4: جدول العينات وتنفيذ Patch Extraction

**الملف:** `workflow.blade.php` (سطر 846-980)

**يظهر فقط** عندما يكون `operation_type = patch_extraction` والخادم مؤكد.

- **جدول العينات** — صفحات من 50 عينة
  - أعمدة: تحديد، #، ملف، حالة، مرض، عضو، صبغة، مصدر، فئة، حجم، حالة التقطيع، حجم القطعة، التكبير، الجودة
- **تحديد الكل** — checkbox في رأس الجدول
- **عداد المحدد** — يعرض عدد العينات المحددة
- **زر "Execute Patch Extraction"** — مع تأكيد قبل التنفيذ
- **شريط معلومات** — يعرض الخادم وحجم القطعة والتكبير المختارين

---

## 4. الـ Controllers

### DashboardController::workflow()

**الملف:** `app/Http/Controllers/Admin/DashboardController.php` (سطر 783-909)

**المسؤوليات:**
1. قراءة جميع معلمات الفلتر منطلب GET
2. بناء استعلام `Sample` مع joins على `cases` و `clinical_slide_case_information`
3. تطبيق الفلاتر dinamicamente حسب المدخلات
4. تطبيق فلتر `uniqueness` (عينة واحدة لكل حالة عبر `MIN(id)`)
5. جلب قوائم الخيارات من قاعدة البيانات:
   - `organs`, `stains`, `dataSources`, `categories`, `diseaseTypes`, `tileSizes`
   - `magnifications` (نشطة فقط), `aiModels` (نشطة فقط)
   - `servers` (نشطة فقط), `patchSizes` (نشطة فقط)
6. تمرير البيانات إلى View مع pagination (50 عينة/صفحة)

### OperationsController

**الملف:** `app/Http/Controllers/Admin/OperationsController.php` (273 سطر)

#### dispatchPatchExtraction() (سطر 28-63)

1. التحقق من صحة المدخلات:
   - `sample_ids[]` — مصفوفة معرفات العينات (موجودة في `samples`)
   - `server_id` — معرف الخادم (موجود في `servers_names`)
   - `patch_size_id` — معرف حجم القطعة (موجود في `patch_sizes`)
   - `magnification_id` — معرف التكبير (موجود في `magnifications`)
2. لكل عينة:
   - تحديث `tiling_status` إلى `processing` (إذا لم تكن `processing` بالفعل)
   - حفظ `patch_server_id`, `patch_size_id`, `magnification_id`
   - إرسال `PatchExtractionJob`
3. إعادة توجيه مع رسالة نجاح

#### dispatchFeatureExtraction() (سطر 73-116)

1. التحقق من صحة المدخلات:
   - `sample_ids[]`, `server_id`, `ai_model_id`
2. لكل عينة:
   - التحقق من أن `tiling_status = done` و `tiles_gdrive_path` موجود
   - تحديث `feature_extraction_status` إلى `processing`
   - حفظ `feature_extraction_ai_model_id` و `feature_extraction_server_id`
   - إرسال `FeatureExtractionJob`
3. إعادة توجيه مع عدد العينات المعالجة والمتجاوزة

#### dispatchTraining() (سطر 135-272)

1. التحقق من صحة المدخلات (14 معلمة):
   - `sample_ids[]` (2 على الأقل), `sample_phases`, `server_id`, `training_head_id`
   - `feature_model_id`, `label_type`, `label_map`, `model_type`, `epochs`
   - `learning_rate`, `bag_size`, `n_classes`, `gdrive_output_dir`
2. فك تشفير `label_map` والتحقق من صحتها (2 فئات على الأقل)
3. التحقق من أن كل عينة لها تجزئة مخصصة (Train/Val/Test)
4. التحقق من وجود عينة واحدة على الأقل Train وواحدة على الأقل Val
5. **كشف تسرب البيانات (Data Leakage):**
   - إذا عينتان لهما نفس `case_id`但在 تجزئتين مختلفتين → خطأ
6. التحقق من أهلية العينات (`feature_extraction_status = completed`)
7. إنشاء سجل `TrainingRun` في قاعدة البيانات
8. ربط العينات بالتجزئة عبر pivot table
9. تسجيل توزيع التجزئة في log
10. إرسال `TrainingJob`

---

## 5. المهام الخلفية (Background Jobs)

| المهمة | الملف | المسؤولية |
|--------|-------|----------|
| `PatchExtractionJob` | `app/Jobs/PatchExtractionJob.php` | تحميل الشرائح من GDrive، تقطيعها إلى قطع، رفع النتائج |
| `FeatureExtractionJob` | `app/Jobs/FeatureExtractionJob.php` | إرسال القطع لخادم RunPod واستخراج الميزات |
| `TrainingJob` | `app/Jobs/TrainingJob.php` | تشغيل تدريب CLAM على خادم GPU الخارجي |

---

## 6. النماذج المستخدمة (Models)

| النموذج | الملف | الاستخدام |
|---------|-------|----------|
| `Sample` | `app/Models/Sample.php` | النموذج الرئيسي — جميع الاستعلامات |
| `Organ` | `app/Models/Organ.php` | خيارات الفلتر |
| `Stain` | `app/Models/Stain.php` | خيارات الفلتر |
| `DataSource` | `app/Models/DataSource.php` | خيارات الفلتر |
| `Category` | `app/Models/Category.php` | خيارات الفلتر |
| `PatientCase` | `app/Models/PatientCase.php` | أنواع الأمراض، ربط الحالة السريرية |
| `ClinicalCaseInformation` | `app/Models/ClinicalCaseInformation.php` | فلتر الجنس (Gender) |
| `ServerName` | `app/Models/ServerName.php` | خوالم التنفيذ |
| `PatchSize` | `app/Models/PatchSize.php` | أحجام القطع |
| `Magnification` | `app/Models/Magnification.php` | مستويات التكبير |
| `AiModel` | `app/Models/AiModel.php` | نماذج الذكاء الاصطناعي |
| `TrainingRun` | `app/Models/TrainingRun.php` | سجلات التدريب |

---

## 7. واجهة المستخدم JavaScript

**الملف:** `workflow.blade.php` (سطر 982-1364)

جميعJavaScript موجود في كود inline داخل `@push('scripts')`. لا يوجد Livewire أو Vue أو React.

### الوظائف الرئيسية:

| الوظيفة | المسؤولية |
|---------|----------|
| `onOperationTypeChange()` | إظهار/إخفاء الأقسام حسب نوع العملية |
| `confirmServerBtn click` | إعادة تحميل الصفحة مع معلمات الخادم في URL |
| `refreshCheckboxState()` | تحديث عداد المحدد وزر التنفيذ |
| `selectAll` change | تحديد/إلغاء تحديد جميع العينات |
| `dispatchForm` submit | تأكيد قبل إرسال طلب التقطيع |
| `feUpdate()` | تحديث حالة زر Feature Extraction وعداد المحدد |
| `trUpdate()` | تحديث ملخص التجزئة والتحقق من صحته |
| `assignPhase()` | تعيين تجزئة (Train/Val/Test) لعينة |
| `handleRowCheck()` | إظهار/إخفاء أزرار التجزئة عند التحديد |
| Bulk buttons | تعيين تجزئة لجميع العينات المحددة دفعة واحدة |
| `trForm` submit | تسلسل Label Map إلى JSON قبل الإرسال |
| Label map add/remove | إضافة/حذف صفوف ديناميكياً |
| **Data Leakage Detection** | كشف تلقائي если same case في تجزئتين مختلفتين |

---

## 8. ملفات الـ Layout

| الملف | المسؤولية |
|-------|----------|
| `resources/views/admin/layouts/app.blade.php` | التخطيط الرئيسي (شريط علوي، محتوى، تذييل) |
| `resources/views/admin/partials/sidebar.blade.php` | الشريط الجانبي — رابط "Operations" يظهر عند `admin.workflow` |
| `resources/views/admin/partials/navbar.blade.php` | شريط التنقل العلوي مع قائمة المستخدم |
| `resources/views/admin/partials/footer.blade.php` | التذييل |

---

## 9. تدفق العمل الكامل

```
┌─────────────────────────────────────────────────────────┐
│  الخطوة 1: اختيار نوع العملية                          │
│  ┌─────────────────────────────────────────────┐        │
│  │  Patch Extraction │ Feature Extract │ Training │      │
│  └────────┬──────────┴────────┬────────┴────┬───┘        │
└───────────┼──────────────────┼─────────────┼────────────┘
            ▼                  ▼             ▼
┌────────────────────┐ ┌──────────────┐ ┌────────────────┐
│ الخطوة 2:          │ │ الخادم +     │ │ نموذج CLAM    │
│ الخادم + الحجم +   │ │ النموذج AI   │ │ الكامل مع     │
│ التكبير            │ │              │ │ Label Map +    │
│ → Confirm          │ │              │ │ Split Mgmt    │
└────────┬───────────┘ └──────┬───────┘ └───────┬────────┘
         ▼                    ▼                  ▼
┌────────────────────┐ ┌──────────────┐ ┌────────────────┐
│ الخطوة 3:          │ │ جدول العينات │ │ جدول العينات   │
│ فلاتر العينات      │ │ المؤهلة      │ │ + تجزئة        │
│ (12 فلتر)          │ │ (done)       │ │ Train/Val/Test │
└────────┬───────────┘ └──────┬───────┘ └───────┬────────┘
         ▼                    ▼                  ▼
┌────────────────────┐ ┌──────────────┐ ┌────────────────┐
│ الخطوة 4:          │ │ Dispatch     │ │ Dispatch       │
│ جدول + تحديد +     │ │ Feature      │ │ Training       │
│ Execute            │ │ Extraction   │ │ Run            │
└────────────────────┘ └──────────────┘ └────────────────┘
         │                    │                  │
         ▼                    ▼                  ▼
┌────────────────────┐ ┌──────────────┐ ┌────────────────┐
│ PatchExtractionJob │ │ FeatureExt.  │ │ TrainingJob    │
│ → GDrive download  │ │ Job → RunPod │ │ → CLAM on GPU  │
│ → Tile → Upload    │ │ → Features   │ │ → Model output │
└────────────────────┘ └──────────────┘ └────────────────┘
```

---

## 10. حماية وتحقق

| نوع التحقق | الموقع | الوصف |
|------------|--------|-------|
| Authentication | `routes/web.php` | middleware `auth` على جميع الروابط |
| Validation (PATCH) | `OperationsController:30-36` | التحقق من وجود جميع المعرفات في قاعدة البيانات |
| Validation (FE) | `OperationsController:75-80` | التحقق من وجود server_id و ai_model_id |
| Validation (Training) | `OperationsController:137-153` | 14 قاعدة تحقق شاملة |
| Tiling Status Check | `OperationsController:89` | عينات `tiling_status = done` فقط لـ FE |
| Feature Status Check | `OperationsController:205-209` | عينات `feature_extraction_status = completed` فقط للتدريب |
| Data Leakage Guard | `OperationsController:184-202` | كشف عينات من نفس الحالة في تجزئتين مختلفتين |
| Minimum Samples | `OperationsController:177-182` | عينة واحدة على الأقل Train وواحدة Val |
| Double-dispatch Guard | `OperationsController:42` | منع إرسال عينة `tiling_status = processing` مجدداً |
| Client-side Confirmation | `workflow.blade.php:1088-1098` | تأكيد قبل إرسال طلب التقطيع |

---

## 11. ملخص الملفات

```
routes/web.php                          → تعريف الروابط (4 routes)
app/Http/Controllers/Admin/
  DashboardController.php               → workflow() method (سطر 783-909)
  OperationsController.php              → 3 methods للتنفيذ (273 سطر)
app/Jobs/
  PatchExtractionJob.php                → مهمة تقطيع الشرائح
  FeatureExtractionJob.php              → مهمة استخراج الميزات
  TrainingJob.php                       → مهمة التدريب
resources/views/admin/
  workflow.blade.php                    → الواجهة الرئيسية (1365 سطر)
  layouts/app.blade.php                 → التخطيط الرئيسي
  partials/sidebar.blade.php            → الشريط الجانبي
  partials/navbar.blade.php             → شريط التنقل
  partials/footer.blade.php             → التذييل
app/Models/                             → 12 نموذج مستخدم
```

---

*تم إنشاء هذا التقرير تلقائياً من تحليل الكود المصدري.*
