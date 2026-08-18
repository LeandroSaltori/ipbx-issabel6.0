using System;
using System.Drawing;
using System.Drawing.Imaging;

public class LogoProcessor {
    public static void MakeTransparent(string inputPath, string outputPath, bool whiteText) {
        using (Bitmap src = new Bitmap(inputPath)) {
            Bitmap bmp = new Bitmap(src.Width, src.Height, PixelFormat.Format32bppArgb);
            BitmapData srcData = src.LockBits(new Rectangle(0, 0, src.Width, src.Height), ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
            BitmapData dstData = bmp.LockBits(new Rectangle(0, 0, bmp.Width, bmp.Height), ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);

            unsafe {
                byte* pSrc = (byte*)srcData.Scan0;
                byte* pDst = (byte*)dstData.Scan0;
                int bytes = srcData.Stride * srcData.Height;

                for (int i = 0; i < bytes; i += 4) {
                    byte b = pSrc[i];
                    byte g = pSrc[i + 1];
                    byte r = pSrc[i + 2];
                    byte a = pSrc[i + 3];

                    // White background check (R, G, B > 235)
                    if (r > 230 && g > 230 && b > 230) {
                        pDst[i] = 0;
                        pDst[i + 1] = 0;
                        pDst[i + 2] = 0;
                        pDst[i + 3] = 0;
                    } else {
                        if (whiteText && r < 70 && g < 70 && b < 70) {
                            pDst[i] = 255;
                            pDst[i + 1] = 255;
                            pDst[i + 2] = 255;
                            pDst[i + 3] = a;
                        } else {
                            pDst[i] = b;
                            pDst[i + 1] = g;
                            pDst[i + 2] = r;
                            pDst[i + 3] = a;
                        }
                    }
                }
            }

            src.UnlockBits(srcData);
            bmp.UnlockBits(dstData);
            bmp.Save(outputPath, ImageFormat.Png);
            bmp.Dispose();
        }
    }
}
