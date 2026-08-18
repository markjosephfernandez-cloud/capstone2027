# GREYHOUND HUB 2.0

## REVISED CHAPTERS 1 AND 2

Foundation University

Working research manuscript based on the current implemented system

## PRE-SUBMISSION VERIFICATION NOTES

1. **Official research title — Needs Verification.** Replace the working title on this page with the exact title approved by the research panel.
2. **Automatic publishing — Needs Verification.** The system is designed to publish uploads automatically after post-upload processing and no longer uses a media approval workflow. Before submission, verify that both web and API upload paths save the same published status.
3. **Channel terminology — Needs Verification.** Standardize “General Events” and “Foundation Events,” as well as abbreviated and full department names, across the web system, API, mobile application, and manuscript.
4. **Department-specific colors — Needs Verification.** The manuscript does not claim distinct department color schemes because these were not verified in the current interface.

---PAGEBREAK---

# CHAPTER 1

## THE PROBLEM AND ITS BACKGROUND

## 1.1 Introduction

Universities continuously produce photographs, videos, audio recordings, documents, and other digital assets through academic activities, institutional celebrations, promotional campaigns, student programs, and department events. As these collections increase, media personnel must be able to store, describe, retrieve, protect, and distribute files without depending on inconsistent filenames or personal knowledge of where an asset was saved. When media are distributed across separate drives and loosely organized folders, retrieval becomes slower, duplicate copies accumulate, and institutional content becomes more difficult to manage.

General-purpose cloud storage platforms provide file storage, synchronization, and sharing, but they are not necessarily designed around the specialized workflow of a university creative unit. Their organization is commonly based on filenames and folders, while event-specific classification, institution-oriented metadata, duplicate review, watermark processing, departmental distribution, and media-oriented audit records may require separate tools or manual procedures. Enterprise digital asset management platforms can provide broader capabilities, but their cost, complexity, and configuration requirements may not be appropriate for a university seeking a locally managed system aligned with its own events, departments, and user roles.

Foundation University’s Creative Department manages institutional media produced from university programs and event coverage. The earlier documentation reported that personnel could spend approximately 30 minutes to more than one hour locating requested files and that the existing file-management approach received a satisfaction rating of 5 out of 10. These findings indicated problems in organization, retrieval, duplication, and distribution. They also established the need for a centralized platform in which media can be described using meaningful titles and tags rather than relying only on the physical filename.

Greyhound Hub 2.0 was developed as an AI-assisted digital asset management and media-distribution system for Foundation University. The current system combines a CodeIgniter 4 web administration application, a REST API, a React Native mobile application, a MySQL database, and a local Python/TensorFlow event-classification service. It supports institutional media upload, nested folder management, Display Titles, manual and AI-generated tags, combined metadata search, duplicate review, watermark derivatives, AI Media Passports, general and department media channels, department assignments and requests, notifications, audit records, and role-based account restrictions.

The current version no longer uses a Media Approval Workflow. Instead, the system is designed to process uploaded media automatically and make eligible content available through publication and channel assignment after the required post-upload operations. These operations may include exact and perceptual duplicate checking, event analysis, automatic tagging, AI Media Passport generation, and watermark creation. Publication and department distribution are therefore treated as content-management actions rather than approval stages. This change reduces dependence on a queue-based approval process but makes consistent permissions, metadata, auditability, and responsible institutional upload practices more important.

Media identity in Greyhound Hub 2.0 is divided into a storage identity and a user-facing identity. The uploaded filename remains the basis of the stored filename, subject to security sanitization and collision suffixes, while the Display Title is used as the meaningful label in the interface where available. Users can search using the Display Title, original filename, manual tags, AI classification, event information, and folder details. This arrangement preserves file traceability while allowing descriptive titles to be corrected without requiring the physical file to be renamed.

Artificial intelligence in the system is used as an assistive classification and metadata tool. The active ResNet50 transfer-learning model classifies images into Dal-uy, Graduation, and Kasadyaan. The AI service can return an unknown result when confidence is insufficient, while lower-confidence recognized results can be marked for human review. It also contributes event and media-related tags during upload. General Events is a publication channel and not a separate trained event class. Exact duplicate detection is performed using SHA-256 hashes, while visually similar images are identified using perceptual difference hashes; these hashing functions are separate from the trained event-classification model.

The system also generates an AI Media Passport that records structured information about an uploaded asset. Depending on available data, the passport includes a unique identifier, media identifier, event, department, photographer or uploader, date taken, upload date, AI classification and confidence, model information, rights and usage details, an integrity hash, a QR payload, and version history. The passport is an internal metadata and integrity record. It does not create an embedded Coalition for Content Provenance and Authenticity manifest and is not presented as C2PA-compliant.

Through these functions, Greyhound Hub 2.0 addresses the operational need for a locally controlled, searchable, and accountable institutional media repository. Its contribution is the integration of university-specific AI event classification, human-verifiable metadata, duplicate management, non-destructive watermarking, role-scoped distribution, and web and mobile access within one system designed around Foundation University’s media workflow.

## 1.2 Background and Rationale of the Study

The original Greyhound Hub was introduced in December 2024 to improve file organization and access within the Creative Department. Its development established a centralized foundation for managing institutional content, but continued evaluation and system use revealed limitations that required a broader redesign. The earlier version depended heavily on filenames and folders, provided limited event-specific intelligence, and did not fully support the current requirements for department-oriented distribution, duplicate review, descriptive media titles, versioned metadata records, and mobile content channels.

One recurring difficulty was the difference between a filename and the way users naturally identify media. A filename may reflect a camera-generated sequence, an uploader’s naming habit, or an abbreviated event name. Renaming the physical file can affect traceability and create collision or compatibility problems, while keeping only the original filename can make retrieval difficult. Greyhound Hub 2.0 addresses this by retaining the uploaded filename as the storage basis and providing a Display Title for use throughout the interface. Manual tags and AI-generated metadata supplement this title so that users can search using terms that describe an event, department, year, format, or other relevant characteristic.

Duplicate media also create operational and storage problems. Exact copies may be uploaded under different names, while visually similar images may differ in compression or minor processing. The current system creates a SHA-256 digest for exact comparison and a perceptual difference hash for image-similarity comparison. Suspected duplicates are placed in a review queue where authorized personnel may preview, download, or restore them to the active media library. This approach prevents duplicate decisions from being fully automatic and allows staff to exercise judgment when perceptual similarity does not necessarily mean that two images are interchangeable.

The AI component was refined to focus on institution-specific event recognition. The current ResNet50 model uses transfer learning to classify Dal-uy, Graduation, and Kasadyaan images. The present dataset contains 359 images divided among training, validation, and test directories. A preliminary internal test classified 46 of 48 test images correctly and returned two images as unknown, producing an observed accuracy of 95.83 percent on that test set. Because the dataset is small and institution-specific, the result does not establish general performance beyond the evaluated images. The system therefore retains confidence thresholds and human review instead of treating every AI output as final.

Greyhound Hub 2.0 further introduces automatic AI tagging and an AI Media Passport. The Passport consolidates descriptive, administrative, rights-related, and AI-generated metadata in a versioned record. Its hash-based integrity value can help identify whether the referenced file content has changed. However, it is not a substitute for legal ownership documentation, subject-release authorization, or an external cryptographic provenance standard. This distinction ensures that the system’s capabilities are represented accurately.

The media workflow was also changed by removing the previous approval architecture. Approval tables and approval-history fields are no longer part of the current system. Uploaded media are intended to proceed through post-upload automation and publication without a tiered Media Approval Workflow. Authorized users can subsequently manage channel placement, distribution, withdrawal, metadata, and access according to their role. This design supports faster availability but requires clear institutional upload policies because the software does not independently verify whether every identifiable person in a photograph has granted a media release.

Watermark processing supports controlled distribution without altering the source asset. For supported image formats, the system can create a separate visible-watermark derivative using an authorized Foundation University or department mark. The original file remains unchanged, while the derivative is stored in the system’s Watermark storage location and may be used for download or distribution. Watermarking communicates institutional ownership or source identity, but it cannot by itself prevent copying or establish complete copyright compliance.

Finally, the system broadens access through general and department media channels, a mobile application, and department-specific distribution controls. Creative Staff may organize and transmit media, while Department users receive content assigned to their department and may submit media requests according to their permissions. Writers have view- and download-oriented access, and Super Administrators retain broader account, audit, and permanent-deletion authority. These restrictions align functions with institutional responsibilities and reduce unnecessary access to administrative operations.

The rationale for the study is therefore grounded in a continuing institutional problem: Foundation University requires a media-management environment that makes content easier to identify, retrieve, verify, organize, and deliver across departments. Greyhound Hub 2.0 responds through an integrated and locally managed platform rather than through a collection of unrelated storage, AI, watermarking, and distribution tools.

## 1.3 Statement of the Problem

Foundation University’s Creative Department manages a growing collection of institutional media that must be organized, retrieved, processed, and distributed to appropriate users and departments. Reliance on filenames, manually maintained folders, and disconnected procedures can result in slow retrieval, inconsistent metadata, duplicate uploads, uncertain event classification, and limited traceability. The study therefore seeks to determine how an AI-assisted digital asset management and media-distribution system can improve the management of Foundation University’s institutional media.

Specifically, the study seeks to answer the following questions:

1. How can the system centralize the upload, organization, search, retrieval, and lifecycle management of institutional media?
2. How can Display Titles, original filenames, manual tags, AI-generated tags, event metadata, and folder information improve media discoverability?
3. How can exact and perceptual hashing help identify duplicate or visually similar media while retaining human review of uncertain matches?
4. How accurately can the implemented ResNet50 transfer-learning model classify the currently supported university events of Dal-uy, Graduation, and Kasadyaan using the available dataset?
5. How can confidence thresholds and a human-review interface reduce the risk of relying on uncertain AI classifications?
6. How can the AI Media Passport provide structured metadata, rights information, version history, and hash-based integrity information for uploaded media?
7. How can non-destructive watermark derivatives support institutional identification while preserving original files?
8. How can general and department channels, media requests, assignments, notifications, and the mobile application support department-oriented access and distribution?
9. How can role-based permissions, account restrictions, privacy acknowledgement, and audit records support accountable use of the platform?
10. How acceptable is Greyhound Hub 2.0 in terms of the applicable ISO/IEC 25010:2023 software-quality characteristics, including functional suitability, performance efficiency, usability, reliability, security, and maintainability?

## 1.4 Objectives of the Study

### 1.4.1 General Objective

The general objective of the study is to develop and evaluate Greyhound Hub 2.0, an AI-assisted digital asset management and media-distribution system that improves the organization, discoverability, integrity recording, duplicate management, controlled distribution, and mobile accessibility of Foundation University’s institutional media.

### 1.4.2 Specific Objectives

Specifically, the study aims to:

1. develop a centralized web-based media library that supports upload, nested folders, Display Titles, original-filename traceability, manual tags, metadata search, bulk operations, trash, restoration, download, and authorized permanent deletion;
2. implement an automatic post-upload process that performs the applicable duplicate checking, AI event analysis, automatic tagging, AI Media Passport generation, watermark processing, and publication or channel assignment without a tiered Media Approval Workflow;
3. identify exact duplicate files through SHA-256 comparison and visually similar images through perceptual difference hashing, with an authorized review interface for previewing, downloading, and restoring suspected duplicates;
4. implement a ResNet50 transfer-learning model for the classification of Dal-uy, Graduation, and Kasadyaan images, evaluate it using labeled validation and test data, and provide confidence-based unknown and review states for uncertain predictions;
5. generate a versioned AI Media Passport containing available descriptive, administrative, AI-generated, rights-related, and integrity metadata for uploaded media;
6. generate non-destructive visible-watermark derivatives for supported image formats while preserving the original uploaded file;
7. provide general and department media channels, department requests and assignments, notifications, and a React Native mobile application for authorized discovery, viewing, streaming, engagement, and downloading of published media;
8. enforce role- and department-based permissions for Super Administrators, Creative Staff, Writers, and Department users, including active-account restrictions and audit records for defined security and administrative activities; and
9. evaluate the developed system using applicable ISO/IEC 25010:2023 quality characteristics and document the environment, participants, instruments, data volume, and procedures used for each reported metric.

## 1.5 Significance of the Study

**Foundation University.** The study provides the university with a locally managed platform for preserving, organizing, and distributing institutional media. Consolidated metadata, audit records, department channels, and role restrictions can strengthen organizational continuity and reduce dependence on personal storage practices.

**Creative Department personnel.** The system can reduce the time required to describe and locate media through Display Titles, manual tags, AI-generated tags, event classification, structured folders, and combined search. Duplicate review, bulk operations, watermark derivatives, and automatic Passport generation also reduce repetitive file-processing work.

**System administrators.** Super Administrators and authorized Creative Staff receive tools for account management, role enforcement, storage and upload summaries, activity review, media lifecycle operations, and department distribution. These functions provide a clearer view of system use without claiming infrastructure monitoring that the current dashboard does not perform.

**University departments.** Department users can request media, receive assigned files and folders, access department-scoped content, and complete distribution transactions. This reduces informal transfer methods and creates a more traceable institutional exchange of media.

**Writers, faculty, students, and other authorized media consumers.** Searchable general and department channels and the mobile application provide more convenient access to published institutional content. The practical benefit depends on the access policies assigned by Foundation University.

**Future researchers and developers.** The study provides a reference for institution-specific event classification, human-in-the-loop AI review, metadata passports, hash-based duplicate control, non-destructive watermarking, role-based distribution, and integrated web/mobile media systems. It also identifies limitations that may guide future work, including broader event datasets, formal C2PA credentials, stronger consent-management processes, and additional evaluation metrics.

## 1.6 Scope and Limitations of the Study

### 1.6.1 Scope

Greyhound Hub 2.0 covers the management and distribution of Foundation University media through a CodeIgniter 4 web application, a versioned REST API, a MySQL database, a React Native mobile application, and a local Python/TensorFlow AI service. The system accepts supported images, videos, audio files, and documents. Uploaded files are organized in a hierarchical media library and may be assigned Display Titles, manual tags, descriptions, folders, departments, and distribution channels.

The file-management scope includes nested folder creation, folder-based upload, preservation of uploaded directory structure, folder and file search, bulk tagging, bulk download, trash, restoration, and authorized permanent deletion. The physical filename is based on the original client filename but may be sanitized for safe storage or given a suffix when a collision occurs. The Display Title is the preferred interface label where available and can be changed independently of the stored file.

Search covers the Display Title, filename, manual tags, AI classification, detected metadata, Passport event data, and folder names or paths where supported by the relevant web, API, or mobile endpoint. The system also supports file-type and workflow filters.

Duplicate processing includes exact comparison using SHA-256 for uploaded media and perceptual difference hashing for visually similar supported images. Suspected duplicates are recorded separately for authorized review. They can be previewed, downloaded, or restored to the active library according to user permissions.

AI analysis is limited to the currently deployed ResNet50 transfer-learning image classifier. Its trained classes are Dal-uy, Graduation, and Kasadyaan. The service returns an event only when its model-confidence requirement is met, and recognized results below the system’s review threshold may be marked for human verification. Filename, description, or tag keywords may be used as fallback metadata when model inference is unavailable; such fallback results must not be described as image-based AI recognition.

The present dataset contains 359 labeled images: 263 training images, 48 validation images, and 48 test images. Training augmentation in the current implementation includes horizontal flipping, rotation, and zoom. The latest internal test produced 46 correct classifications and two unknown results from the 48 test images, equivalent to 95.83 percent observed accuracy on that set. Formal reporting of precision, recall, F1-score, and a confusion matrix is outside the present implementation unless these measurements are subsequently generated and documented.

Post-upload processing includes applicable AI analysis, automatic tags, Passport generation, watermark processing, duplicate recording, and intended publication or channel assignment. No Media Approval Workflow is included. General Events is a media channel, while Dal-uy, Graduation, and Kasadyaan are AI classes. EXPO and a generalized “General University Events” class are not included in the active trained model.

The AI Media Passport records available media identity, event, department, photographer or uploader, selected dates, AI classification and confidence, model and processing details, rights and restrictions, SHA-256 integrity data, QR payload, and version history. The Passport is stored within Greyhound Hub and is not an embedded C2PA manifest or a legally certified digital signature.

Watermarking is limited to supported image formats, including JPEG, PNG, and WebP in the current service. It creates a separate derivative and does not modify the original asset. Watermark availability depends on enabled settings, the upload context, available institutional logo files, and user permissions.

Distribution includes general and department channels, department media requests, assignments, direct sending of authorized files or folders, notifications, and completion records. The mobile application includes published-media feeds, channel subscriptions, search and search history, media viewing, byte-range video streaming, downloads, likes, dislikes, comments, watch history, and download history where enabled by the API.

The user scope consists of Super Administrators, Creative Staff or Administrators, Writers, and Department users. Permissions are enforced according to role, account status, and department assignment. Deactivated accounts cannot continue normal authenticated access, and existing tokens can be revoked. Defined account, security, upload, distribution, deletion, and administrative actions are recorded in audit or activity logs.

### 1.6.2 Limitations

The AI classifier is limited to three institution-specific event classes and a relatively small dataset. Its performance may decrease when images differ substantially from the training data in lighting, viewpoint, crowd composition, venue, or visual content. An unknown or review result does not identify a new event; it indicates that the system lacks sufficient confidence for the supported classes.

The preliminary 95.83 percent result is based on 48 internal test images and should not be generalized to all Foundation University media without additional data and independent evaluation. The current test script does not yet provide a full precision, recall, F1-score, or confusion-matrix report.

Perceptual hashing applies to supported images and estimates visual similarity; it can produce false positives or miss substantially transformed copies. Exact SHA-256 matching identifies byte-identical content but not visually equivalent files with different encodings. Final duplicate decisions therefore remain with authorized users.

Visible watermarks communicate institutional identity but do not guarantee prevention of unauthorized copying. The watermark service creates derivative files and does not constitute digital-rights management or legal proof of ownership.

The AI Media Passport is an internal structured record. Although it contains integrity metadata, it is not C2PA-compliant, does not embed a signed C2PA manifest into the media, and does not independently prove authorship or legal rights.

The system records user acknowledgement of its privacy policy, but it does not perform facial recognition, biometric matching, or automatic matching of photographed individuals to signed media-release forms. Responsibility for lawful collection and publication remains subject to Foundation University policy and applicable privacy law.

Automatic publication removes delays associated with a formal approval queue but increases the importance of correct role assignment and responsible uploading. Consistency of the published status across all upload entry points must be verified before final deployment and research evaluation.

The current system supports department-scoped access and channels, but distinct department-specific interface colors were not verified and are not treated as an implemented research feature. Channel names must also be standardized between the backend and mobile application before final evaluation.

The study is limited to the Foundation University environment, available users, configured infrastructure, and institutional media supplied for development and testing. Its findings may not directly represent the requirements or performance of larger commercial digital asset management deployments.

---PAGEBREAK---

# CHAPTER 2

## REVIEW OF RELATED LITERATURE AND STUDIES

## 2.1 Review of Related Literature

### 2.1.1 Digital Asset Management, Metadata, and Discoverability

Digital asset management involves more than storing files in a repository. An institutional system must maintain descriptive information, logical organization, retrieval controls, lifecycle states, and appropriate access to digital content. Metadata provides the descriptive and administrative context that enables users to find and interpret an asset even when its physical filename is not meaningful. Harper, Kumer, Stuart, and Meszaros (2022) discussed AI-informed metadata tagging as a means of improving resource discovery in academic-library settings. Their work supports the use of machine-assisted metadata while also indicating that useful discovery depends on the quality and consistency of the resulting descriptions.

For Greyhound Hub 2.0, metadata is distributed across the Display Title, original filename, manual tags, AI-generated tags, event classification, folder information, department, and AI Media Passport. The distinction between Display Title and stored filename is significant. A camera or uploader filename can be preserved for traceability, while a descriptive title can be shown in the interface and indexed for retrieval. Manual tags remain necessary because users possess contextual knowledge that may not be visible in an image, such as the intended department, campaign, audience, or internal event terminology.

Folder hierarchies and metadata search serve complementary purposes. Folders provide an understandable browsing structure, while search allows retrieval across locations. A system that depends only on folders may require users to know where an asset was stored; a system that depends only on automatic tags may fail when metadata is incomplete. Greyhound Hub 2.0 therefore combines nested folders with title-, tag-, event-, and metadata-based search.

### 2.1.2 AI-Based Metadata Tagging and Event Classification

Artificial intelligence can assist multimedia management by converting visual content into searchable labels. However, the role of AI in an institutional repository should be defined narrowly enough to match the available model and data. Greyhound Hub 2.0 uses image classification for selected Foundation University events rather than general object detection, facial recognition, emotion analysis, or generative content recommendation.

He, Zhang, Ren, and Sun (2016) introduced residual learning as a method for training deep neural networks while reducing optimization difficulties associated with increasing depth. ResNet50 applies this residual architecture and is widely used as a pretrained base for image-classification tasks. Transfer learning reuses features learned from a larger source dataset and adapts them to a smaller target problem. TensorFlow’s transfer-learning guidance explains that pretrained layers can be frozen, extended with task-specific layers, and then fine-tuned using the new dataset (TensorFlow, n.d.). This approach is relevant when an institution does not have enough labeled images to train a deep network from the beginning.

The current event classifier applies this approach to Dal-uy, Graduation, and Kasadyaan. Confidence handling is important because a classifier will otherwise select one of its known classes even when an image belongs to an unsupported event. An unknown threshold and a separate human-review threshold allow the system to communicate uncertainty. These controls are especially important for a small, local dataset whose class distribution and visual conditions may not represent future uploads.

AI-generated tags can improve initial organization, but they should not replace user-created metadata. Event labels derived from the model can be combined with media type, format, year, folder, and review indicators. Manual tags then allow Creative Department personnel to correct, expand, or contextualize the automated description.

### 2.1.3 Human-AI Interaction and Human Review

Amershi et al. (2019) proposed 18 guidelines for human-AI interaction and emphasized the need for systems to communicate capabilities, show relevant contextual information, support correction, and behave appropriately when uncertain or incorrect. These principles support the use of AI as an assistant rather than an unquestionable decision-maker.

Greyhound Hub 2.0 applies this concept by storing the predicted class, confidence, model information, and review status and by allowing authorized personnel to inspect and correct uncertain event classifications. The iFound review interface provides a place for human verification. This is more appropriate than silently assigning every upload to the highest-scoring class. The system’s AI output should therefore be described as classification assistance and metadata support, not as autonomous institutional judgment.

### 2.1.4 Exact and Perceptual Duplicate Detection

Duplicate control is necessary in media repositories because repeated files consume storage, fragment metadata, and make search results less useful. Exact duplication and perceptual similarity are different technical problems. A cryptographic digest such as SHA-256 changes when the file’s bytes change, making it suitable for identifying byte-identical copies. It does not identify an image that has been resized, recompressed, or minimally edited.

Perceptual hashing creates a compact representation of visual characteristics so that similar images can be compared by the distance between their hashes. Du, Ho, and Cong (2020) reviewed perceptual image hashing and described its use in multimedia identification and authentication. Perceptual comparison is useful for near-duplicate discovery, but its threshold represents a trade-off: a permissive threshold may identify unrelated images as similar, while a strict threshold may miss altered duplicates.

Greyhound Hub 2.0 combines SHA-256 exact matching with a 64-bit perceptual difference hash for supported images. Suspected duplicates are recorded for review rather than being treated as proven duplicates. This design recognizes that perceptual similarity is an algorithmic estimate and that staff may need to preserve multiple photographs from the same scene even when they look similar.

### 2.1.5 Visible Watermarking and Preservation of Originals

Digital watermarking has applications in ownership communication, authentication, annotation, and copyright-related media handling. Begum and Uddin (2020) reviewed visible and invisible watermarking techniques and noted that visible marks may identify the copyright holder or source. The practical effectiveness of a watermark depends on its placement, visibility, robustness, and effect on the usefulness of the image.

Greyhound Hub 2.0 uses visible watermark derivatives rather than modifying the original upload. This non-destructive approach preserves the source asset for archival and internal purposes while providing a marked version for selected distribution or download contexts. The approach is operationally appropriate for a university repository because it separates preservation from presentation. Nevertheless, a visible watermark is a deterrent and source indicator; it does not prevent copying and is not a substitute for copyright policy or access control.

### 2.1.6 Metadata Passports, Integrity, and Digital Provenance

Digital provenance concerns the origin and history of an asset. Provenance information may include who created or uploaded content, when it was created or processed, what system handled it, and whether the content changed. Simmons and Winograd (2024) examined the combination of standards-based metadata, cryptography, and watermarking for broadcast-media provenance. Their study shows that provenance involves relationships among metadata, cryptographic validation, and the media itself rather than the presence of a database field alone.

The Coalition for Content Provenance and Authenticity publishes the C2PA technical specification for signed manifests and Content Credentials. Version 2.4 is dated April 2026 (C2PA, 2026). A compliant C2PA implementation requires more than recording a checksum: it requires the creation and validation of the specified manifest and cryptographic assertions.

Greyhound Hub 2.0’s AI Media Passport is related to provenance but has a narrower function. It consolidates internal descriptive, administrative, AI, rights, and integrity metadata and retains version snapshots. Its SHA-256 value can be compared with file content to detect change, and its QR payload can support retrieval of the corresponding record. Because it does not embed and sign a C2PA manifest, the Passport should be described as an internal metadata and integrity record rather than a C2PA-compliant credential. C2PA remains relevant as a benchmark and possible future extension.

### 2.1.7 Privacy, Role-Based Access, and Accountability

Institutional photographs and videos can contain personal information. Republic Act No. 10173, or the Data Privacy Act of 2012, establishes obligations concerning the processing and protection of personal information in the Philippines (National Privacy Commission, 2012). National Privacy Commission guidance also reminds organizations and individuals to consider lawful processing when sharing photographs and videos containing personal data. For a university media system, privacy must therefore be addressed through institutional policy, purpose limitation, appropriate access, security, and responsible publication.

The current system records user acknowledgement of a privacy policy, but this is different from obtaining a media release from every identifiable subject. The software does not perform face recognition or match people to consent documents. Chapter 2 therefore does not treat automatic publication as proof of consent. The institution remains responsible for establishing when media may be collected, uploaded, published, or withdrawn.

Role-based access control assigns permissions through organizational roles rather than individually configuring every action for every user. Sandhu, Ferraiolo, and Kuhn (2000) presented the NIST model for role-based access control and described flat, hierarchical, constrained, and symmetric forms of RBAC. The model supports the separation of responsibilities applied in Greyhound Hub 2.0: Super Administrators control broader administrative functions; Creative Staff manage media and distribution; Writers receive view- and download-oriented access; and Department users operate within assigned department functions.

Account activation, password protection, token revocation, department scope, and audit records complement role permissions. Auditability does not guarantee lawful use, but it helps the institution reconstruct defined actions and investigate unexpected changes. These controls are especially important because the current workflow removes a pre-publication approval queue.

### 2.1.8 Software Quality, Usability, and Performance

ISO/IEC 25010:2023 provides a product-quality model that includes functional suitability, performance efficiency, compatibility, interaction capability, reliability, security, maintainability, flexibility, and safety (ISO/IEC, 2023). These characteristics provide an appropriate basis for evaluating Greyhound Hub 2.0, but each selected characteristic must be linked to a defined instrument or technical test.

Usability is particularly important because Creative Department and department personnel may have different levels of technical experience. Display Titles, consistent labels, clear folder navigation, bulk operations, visible AI confidence, understandable duplicate previews, and confirmation for destructive actions can reduce operating errors. Mobile navigation, channel subscriptions, search history, and media playback should likewise be evaluated using task-based or respondent-based measures rather than assumed to be usable because they are present.

Performance evaluation should distinguish system functions. Search response time, upload duration, AI inference time, watermark-generation time, API response time, download throughput, and video-streaming behavior represent different workloads. Blinowski, Ojdowska, and Przybyłek (2022) found that the relative performance of monolithic and microservice architectures depends on deployment and workload conditions; microservices should not be assumed to be faster in every environment. Greyhound Hub 2.0 is more accurately described as a CodeIgniter application with a separate local AI service than as a complete microservice architecture.

De Martino and Palomba (2025) reviewed non-functional requirements in machine-learning-enabled systems and identified multiple requirement classes and engineering challenges. Their findings support the need to evaluate not only classifier accuracy but also usability, reliability, maintainability, security, and performance surrounding the model.

### 2.1.9 Mobile Media Channels and Department Distribution

Institutional media management includes delivery as well as storage. A department may require selected files for its own programs, while general university content may be distributed to a broader audience. Channel-based organization provides a recognizable destination for published content, and department assignments create a controlled transfer record between the Creative Department and requesting units.

Greyhound Hub 2.0 combines General Events and department channels with media requests, assignments, notifications, and a React Native mobile client. React Native uses a component-based approach to build native applications for multiple platforms while reusing application logic (Meta Platforms, Inc., n.d.). The mobile application extends repository content into feeds, subscriptions, search, playback, engagement, and downloads. These consumption functions depend on backend publication, channel metadata, and role or access rules; consequently, channel terminology must remain consistent across the system.

## 2.2 Review of Related Studies

### 2.2.1 Residual Networks and Transfer Learning for Image Classification

He et al. (2016) evaluated deep residual networks and demonstrated that residual connections make substantially deeper networks easier to optimize. Their ResNet architecture achieved strong performance on ImageNet and became a foundation for later classification and detection applications. The study supports Greyhound Hub 2.0’s selection of ResNet50 as a pretrained visual feature extractor.

The relationship to the current system is methodological rather than a direct claim that the same performance will be achieved. Greyhound Hub uses a much smaller, institution-specific dataset and only three event classes. Transfer learning reduces the amount of training required, but model quality still depends on representative images, correct labels, balanced classes, suitable augmentation, and independent evaluation. The study therefore supports the architecture but does not remove the need for local validation and human review.

### 2.2.2 AI-Informed Metadata for Resource Discovery

Harper et al. (2022) examined AI-informed approaches to metadata tagging in the context of academic-resource discovery. Their work supports the proposition that automated tags can improve access to collections when they are incorporated into established metadata practice. It also suggests that AI output should be reviewed as part of a broader descriptive workflow.

Greyhound Hub applies this concept by combining event classification and automatically derived tags with Display Titles and manual tags. The system does not depend on a single AI label to describe an asset. Instead, machine output becomes one searchable component that staff may review or correct.

### 2.2.3 Perceptual Hashing for Image Comparison

Du et al. (2020) surveyed perceptual hashing methods for image authentication and described the common process of preprocessing, feature extraction, and compact hash generation. Their analysis supports perceptual hashing as a method for comparing content even when file bytes differ.

In Greyhound Hub 2.0, the perceptual difference hash is used for visually similar image detection, while SHA-256 is reserved for exact content matching. This separation is consistent with the different properties of the two methods. The system’s duplicate review queue also addresses the survey’s implication that thresholds and transformations affect reliability.

### 2.2.4 Watermarking for Media Identification

Begum and Uddin (2020) reviewed watermarking techniques by domain, perceptibility, document type, and purpose. Their work identifies ownership communication and authentication among common applications. For Greyhound Hub 2.0, the relevant concept is visible watermarking applied to a derived copy.

The current implementation does not attempt to provide invisible forensic watermarking or robust recovery after complex image transformations. Its goal is operational: to prepare an institutionally marked derivative while preserving the original. The study supports the use of watermarking but also indicates why the system should not claim complete copyright protection.

### 2.2.5 Open Standards and Provenance Authentication

Simmons and Winograd (2024) analyzed open standards, authenticated metadata, watermarking, and cryptography in a broadcast-media scenario. They concluded that C2PA metadata and complementary watermarking can contribute to interoperable provenance. This provides a useful comparison for the AI Media Passport.

The current Passport records provenance-related information internally, but it does not implement the complete standards-based process examined in the study. The difference identifies a future development path: a later version could create signed C2PA manifests and validate credentials when media move outside Greyhound Hub.

### 2.2.6 Human-AI Interface Guidelines

Amershi et al. (2019) validated human-AI interaction guidelines through evaluations involving design practitioners and multiple AI-infused products. Their guidelines include communicating what the system can do, showing contextual information, supporting efficient correction, and responding appropriately to uncertainty.

These principles support Greyhound Hub’s decision to expose AI confidence and review status and to allow human correction. They also support the use of unknown results for unsupported or low-confidence images. The user remains responsible for the final descriptive metadata and institutional use of the asset.

### 2.2.7 Architecture and Non-Functional Requirements

Blinowski et al. (2022) compared monolithic and microservice systems under controlled conditions and showed that architectural choice produces performance and scalability trade-offs. Their findings caution against presenting one architecture as universally superior. Greyhound Hub’s separate AI service isolates model inference from the main web application, but most business functions remain within the CodeIgniter application.

De Martino and Palomba (2025) systematically reviewed 130 studies concerning non-functional requirements in machine-learning-enabled systems. They identified numerous requirement categories and challenges, reinforcing the need for a system evaluation that considers the complete application rather than reporting only classifier accuracy. This supports the inclusion of ISO/IEC 25010 characteristics in the evaluation of Greyhound Hub 2.0.

## 2.3 Related Technologies and System Concepts

### 2.3.1 CodeIgniter 4, PHP, and MySQL

CodeIgniter 4 is the principal framework of the web administration system and REST API. Its Model-View-Controller organization separates data access, presentation, and request flow (CodeIgniter Foundation, n.d.). PHP implements the server-side application, while MySQL stores users, roles, files, folders, tags, Passport records, duplicate records, watermarks, channels, requests, assignments, notifications, and audit data. Database migrations define schema changes, including the removal of approval structures and addition of Display Titles.

### 2.3.2 REST API and React Native Mobile Application

The REST API provides versioned endpoints for the mobile application and other authorized clients. Bearer-token authentication and role checks protect functions that require an authenticated account. The React Native application consumes published-media and channel information and provides feeds, search, subscriptions, profiles, media playback, comments, reactions, downloads, and history functions.

### 2.3.3 Python, TensorFlow, Keras, and ResNet50

The local AI service is implemented in Python using TensorFlow and Keras. ResNet50 supplies pretrained convolutional features, while task-specific layers produce probabilities for the three university-event classes. The service communicates its prediction, confidence, version, and processing information to the web application. Separating inference from the primary PHP process allows model dependencies to be managed independently, although availability of the local service remains an operational dependency.

### 2.3.4 SHA-256 and Perceptual Difference Hashing

SHA-256 produces a deterministic digest from file bytes and is used for exact duplicate comparison and Passport integrity data. A difference hash reduces an image to a small representation based on relative visual changes and compares hashes using bit distance. These methods serve separate purposes and should not be described as one AI model.

### 2.3.5 EXIF and Structured Media Metadata

Exchangeable Image File Format metadata may provide capture information such as date taken when it is present in an image. Greyhound Hub combines available embedded data with upload records, user-entered information, AI results, rights fields, and system-generated identifiers. Missing EXIF fields do not necessarily indicate an error because many files have been exported, compressed, or stripped of metadata.

### 2.3.6 Display Titles and Manual Tags

The Display Title is a user-facing description independent of the physical filename. The stored filename preserves upload traceability but may be sanitized or suffixed to prevent unsafe paths and collisions. Manual tags represent contextual terms supplied by users, while AI-generated tags represent system-derived information. Indexing both types improves retrieval and allows human knowledge to supplement automated classification.

### 2.3.7 AI Media Passport

The AI Media Passport is a versioned internal metadata record linked to a media asset. It consolidates identity, event, contributor, date, AI, rights, restriction, integrity, and QR-related data. Its purpose is accountability and structured retrieval within the system. It is not a government-issued passport, a legal certificate of ownership, or a C2PA Content Credential.

### 2.3.8 Non-Destructive Watermark Derivatives

Non-destructive processing creates a new marked file rather than overwriting the source. This maintains an archival original while allowing the institution to distribute a visibly identified derivative. The current implementation stores these outputs in the Watermark storage area and supports selected raster-image formats.

### 2.3.9 General and Department Media Channels

Channels classify media by intended audience or institutional unit. General Events serves general university distribution, while department channels and assignments support department-specific access. Channel-based delivery is distinct from AI classification: a media item may be classified as a particular event and still be published to a general or department channel.

## 2.4 Synthesis of the Reviewed Literature and Studies

The reviewed literature establishes that an institutional media repository requires coordinated attention to metadata, retrieval, automation, integrity, security, usability, and distribution. Metadata research supports the combination of machine-generated and human-created descriptions. ResNet and transfer-learning literature supports adapting a pretrained classifier to a limited university-event dataset, while human-AI interaction research supports confidence communication, unknown outcomes, and correction rather than unquestioned automation.

Duplicate-management literature distinguishes exact byte comparison from perceptual image similarity. This distinction supports Greyhound Hub’s combined use of SHA-256 and difference hashes and explains the need for a review queue. Watermarking literature supports the creation of visible institutional derivatives but also shows why such marks should not be treated as complete copyright enforcement.

Provenance literature and the C2PA specification provide a reference point for the AI Media Passport. They support the value of origin, history, and integrity information while demonstrating that a database record and file hash are not equivalent to a signed interoperable credential. The current Passport should therefore be evaluated according to the metadata and integrity functions it actually provides.

Privacy and role-based access literature supports purpose limitation, institutional policy, role-scoped permissions, account controls, and auditability. These controls are important in an automatically published workflow, but they do not replace subject consent where consent is legally or institutionally required. The system must be evaluated as a tool that supports governance rather than as software that independently guarantees compliance.

Finally, software-quality research indicates that classifier accuracy is only one part of system quality. Search, upload processing, AI availability, watermark processing, streaming, security, usability, reliability, and maintainability must also be considered. The reviewed studies collectively support the integrated design of Greyhound Hub 2.0 while defining boundaries for its claims.

## 2.5 Research Gap

Existing storage services, digital asset management platforms, AI classifiers, duplicate-detection utilities, watermarking tools, and mobile publishing systems commonly address only portions of the institutional media lifecycle. The reviewed literature provides strong foundations for individual functions such as metadata tagging, transfer-learning classification, perceptual hashing, watermarking, provenance, role-based access, and human-AI interaction. However, these functions are frequently studied or deployed as separate components and are not necessarily configured for the events, departments, roles, and distribution practices of a specific university Creative Department.

Within the context of Foundation University, the operational gap is the absence of one locally managed platform that combines: preservation of storage filenames with meaningful Display Titles; manual and AI-generated tags; search across titles, tags, events, and folders; exact and perceptual duplicate review; institution-specific event classification with uncertainty and human correction; versioned Media Passport metadata and integrity hashes; non-destructive watermark derivatives; nested folder and file lifecycle management; role- and department-scoped media requests and assignments; and general and department mobile channels.

Greyhound Hub 2.0 addresses this gap through an integrated web, API, AI, database, and mobile environment. Its contribution is not a new general-purpose AI architecture or a claim of complete privacy or C2PA compliance. Its contribution is the applied integration of these technologies into a university-specific digital asset management and distribution workflow. The removal of the Media Approval Workflow further distinguishes the current design from the earlier proposal: governance is implemented through authorized upload, automatic processing, role restrictions, metadata, audit records, withdrawal, and channel management rather than through tiered pre-publication approval.

This gap provides the basis for evaluating whether the integrated system improves media discoverability, organization, duplicate control, AI-assisted metadata, accountability, and department-oriented delivery in the Foundation University setting. It also identifies future research opportunities involving larger event datasets, independent AI validation, signed C2PA credentials, expanded consent management, and broader deployment testing.

## REFERENCES

Amershi, S., Weld, D., Vorvoreanu, M., Fourney, A., Nushi, B., Collisson, P., Suh, J., Iqbal, S., Bennett, P. N., Inkpen, K., Teevan, J., Kikin-Gil, R., & Horvitz, E. (2019). Guidelines for human-AI interaction. *Proceedings of the 2019 CHI Conference on Human Factors in Computing Systems*, 1–13. https://doi.org/10.1145/3290605.3300233

Begum, M., & Uddin, M. S. (2020). Digital image watermarking techniques: A review. *Information, 11*(2), Article 110. https://doi.org/10.3390/info11020110

Blinowski, G., Ojdowska, A., & Przybyłek, A. (2022). Monolithic vs. microservice architecture: A performance and scalability evaluation. *IEEE Access, 10*, 20357–20374. https://doi.org/10.1109/ACCESS.2022.3152803

C2PA. (2026). *C2PA technical specification* (Version 2.4). Coalition for Content Provenance and Authenticity. https://spec.c2pa.org/specifications/specifications/2.4/specs/C2PA_Specification.html

CodeIgniter Foundation. (n.d.). *Models, views, and controllers*. CodeIgniter 4 User Guide. Retrieved August 14, 2026, from https://codeigniter.com/user_guide/concepts/mvc.html

De Martino, V., & Palomba, F. (2025). Classification and challenges of non-functional requirements in ML-enabled systems: A systematic literature review. *Information and Software Technology, 181*, Article 107678. https://doi.org/10.1016/j.infsof.2025.107678

Du, L., Ho, A. T. S., & Cong, R. (2020). Perceptual hashing for image authentication: A survey. *Signal Processing: Image Communication, 81*, Article 115713. https://doi.org/10.1016/j.image.2019.115713

Harper, C., Kumer, A., Stuart, S., & Meszaros, E. (2022). AI-informed approaches to metadata tagging for improved resource discovery. In S. Hervieux & A. Wheatley (Eds.), *The rise of AI: Implications and applications of artificial intelligence in academic libraries* (pp. 89–110). Association of College and Research Libraries.

He, K., Zhang, X., Ren, S., & Sun, J. (2016). Deep residual learning for image recognition. *Proceedings of the IEEE Conference on Computer Vision and Pattern Recognition*, 770–778. https://doi.org/10.1109/CVPR.2016.90

ISO/IEC. (2023). *ISO/IEC 25010:2023 systems and software engineering—Systems and software quality requirements and evaluation (SQuaRE)—Product quality model*. International Organization for Standardization. https://www.iso.org/standard/78176.html

Meta Platforms, Inc. (n.d.). *React Native*. Retrieved August 14, 2026, from https://github.com/facebook/react-native

National Privacy Commission. (2012). *Republic Act No. 10173: Data Privacy Act of 2012*. https://privacy.gov.ph/data-privacy-act/

National Privacy Commission. (n.d.). *Reminder on sharing photos and videos containing personal data*. Retrieved August 14, 2026, from https://privacy.gov.ph/reminder-on-sharing-photos-and-videos-containing-personal-data/

Sandhu, R., Ferraiolo, D. F., & Kuhn, D. R. (2000). The NIST model for role-based access control: Towards a unified standard. *Proceedings of the Fifth ACM Workshop on Role-Based Access Control*, 47–63. https://doi.org/10.1145/344287.344301

Simmons, J. C., & Winograd, J. M. (2024). Interoperable provenance authentication of broadcast media using open standards-based metadata, watermarking and cryptography. *IBC2024 Technical Papers Programme*. https://doi.org/10.48550/arXiv.2405.12336

TensorFlow. (n.d.). *Transfer learning and fine-tuning*. Retrieved August 14, 2026, from https://www.tensorflow.org/guide/keras/transfer_learning
