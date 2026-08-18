using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

public class FastLogo {
    public static void Process(string inputPath, string outputPath, bool makeWhiteText) {
        using (Bitmap src = new Bitmap(inputPath)) {
            Bitmap bmp = new Bitmap(src.Width, src.Height, PixelFormat.Format32bppArgb);
            BitmapData srcData = src.LockBits(new Rectangle(0, 0, src.Width, src.Height), ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
            BitmapData dstData = bmp.LockBits(new Rectangle(0, 0, bmp.Width, bmp.Height), ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);

            int bytes = srcData.Stride * srcData.Height;
            byte[] buffer = new byte[bytes];
            Marshal.Copy(srcData.Scan0, buffer, 0, bytes);

            for (int i = 0; i < bytes; i += 4) {
                byte b = buffer[i];
                byte g = buffer[i + 1];
                byte r = buffer[i + 2];
                byte a = buffer[i + 3];

                // White background check (R, G, B > 225)
                if (r > 225 && g > 225 && b > 225) {
                    buffer[i + 3] = 0; // Alpha = 0 (transparent)
                } else {
                    if (makeWhiteText) {
                        // Check if pixel belongs to dark text (R, G, B < 90)
                        if (r < 90 && g < 90 && b < 90) {
                            buffer[i] = 255;   // B
                            buffer[i + 1] = 255; // G
                            buffer[i + 2] = 255; // R
                        }
                    }
                }
            }

            Marshal.Copy(buffer, 0, dstData.Scan0, bytes);
            src.UnlockBits(srcData);
            bmp.UnlockBits(dstData);
            bmp.Save(outputPath, ImageFormat.Png);
            bmp.Dispose();
        }
    }
}
