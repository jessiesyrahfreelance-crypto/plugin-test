# Coding Task Questions

## 1. Package Optimization
While executing the build command, you will notice that the resulting plugin zip file is considerably large. Identify the issue and implement a solution to reduce the package size while maintaining all required functionality.

**Hint:** Consider how external dependencies are being included in the final build.

---

## 2. Google Drive Admin Interface (React Implementation)

The plugin introduces a new admin menu named **Google Drive Test**. Your task is to complete the missing functionality:

### Requirements:

#### 2.1 Internationalization & UI State Management
- Ensure all user-facing text is translatable using WordPress i18n functions
- Implement proper conditional rendering based on stored credentials and authentication status

#### 2.2 Credentials Management
- Display credential input fields (Client ID, Client Secret) when appropriate
- Show the required redirect URI that users must configure in Google Console
- List the required OAuth scopes for Google Drive API
- **Bonus:** Implement credential encryption before storage
- Use the provided endpoint: `wp-json/wpmudev/v1/drive/save-credentials`

#### 2.3 Authentication Flow
- Implement the "Authenticate with Google Drive" functionality
- Handle the complete OAuth 2.0 flow with proper error handling
- Display appropriate success/error notifications

#### 2.4 File Operations Interface
Once authenticated, implement these sections:

**Upload File to Drive:**
- File selection input with proper validation
- Upload progress indication
- Automatic file list refresh on successful upload
- Error handling and user feedback

**Create New Folder:**
- Text input for folder name with validation
- Button should be disabled when input is empty
- Success/error feedback with list refresh

**Your Drive Files:**
- Display files and folders in a clean, organized layout
- Show: name, type (file/folder), size (files only), modified date
- Include "Download" button for files (not folders)
- Include "View in Drive" link for all items
- Implement proper loading states

---

## 3. Backend: Credentials Storage Endpoint
Complete the REST API endpoint `/wp-json/wpmudev/v1/drive/save-credentials`:
- Implement proper request validation and sanitization
- Store credentials securely in WordPress options
- Return appropriate success/error responses
- Include proper authentication and permission checks

---

## 4. Backend: Google Drive Authentication
Implement the complete OAuth 2.0 authentication flow:
- Generate proper authorization URLs with required scopes
- Handle the OAuth callback securely
- Implement token storage and refresh functionality
- Ensure proper error handling throughout the flow

---

## 5. Backend: Files List API
Create the functionality to fetch and return Google Drive files:
- Connect to Google Drive API using stored credentials
- Return properly formatted file information
- Include pagination support
- Handle API errors gracefully

---

## 6. Backend: File Upload Implementation
Complete the file upload functionality to Google Drive:
- Handle multipart file uploads securely
- Validate file types and sizes
- Return upload progress/completion status
- Implement proper error handling and cleanup

---

## 7. Posts Maintenance Admin Page
Create a new admin menu page titled **Posts Maintenance**:

### Requirements:
- Add a "Scan Posts" button that processes all public posts and pages
- Update `wpmudev_test_last_scan` post meta with current timestamp for each processed post
- Include customizable post type filters
- Implement background processing to continue operation even if user navigates away
- Schedule automatic daily execution of this maintenance task
- Provide progress feedback and completion notifications

---

## 8. WP-CLI Integration
Create a WP-CLI command for the Posts Maintenance functionality:

### Requirements:
- Command should execute the same scan operation as the admin interface
- Include proper command documentation and help text
- Allow customization of post types via command parameters
- Provide progress output and completion summary
- Include usage examples in your implementation

**Example usage should be documented clearly**

---

## 9. Dependency Management & Compatibility
Address potential conflicts with composer packages:

### Requirements:
- Implement measures to prevent version conflicts with other plugins/themes
- Ensure your implementation doesn't interfere with other WordPress installations
- Document your approach and reasoning
- Consider namespace isolation and dependency scoping

---

## 10. Unit Testing Implementation
Create comprehensive unit tests for the Posts Maintenance functionality:

### Requirements:
- Test the scan posts functionality thoroughly
- Include edge cases and error conditions
- Verify post meta updates occur correctly
- Test with different post types and statuses
- Ensure tests can run independently and repeatedly
- Follow WordPress testing best practices

---

## Important Notes

- **Code Standards:** All code must strictly adhere to WordPress Coding Standards (WPCS)
- **Security:** Implement proper sanitization, validation, and permission checks
- **Performance:** Consider performance implications, especially for large datasets
- **Documentation:** Include clear inline comments and documentation
- **Error Handling:** Implement comprehensive error handling throughout

## Submission Guidelines

1. Ensure all functionality works as described
2. Test thoroughly in a clean WordPress environment
3. Include any setup instructions or dependencies
4. Document any assumptions or design decisions made


We wish you good luck!
