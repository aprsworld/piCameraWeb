Camera capture, action, and interface scripts.

capture.php captures the image and then looks in the capture/ directory for executable files. It then runs those files with arguments that are the UNIX timestamp of the image, the path and filename of the fullsize image, and the path and filename of the scaled image.

Those "action" scripts can be used to do pretty much anything post image capture. Overlay text. Upload to remote server. Look for bar codes. Etc.
