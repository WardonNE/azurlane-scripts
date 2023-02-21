package main

import (
	"bufio"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/png"
	"io"
	"math"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/nfnt/resize"
)

var config = new(Config)

func main() {
	var skinCode string
	flag.StringVar(&skinCode, "code", "", "skin code")
	flag.Parse()
	if strings.Trim(skinCode, " ") == "" {
		panic("skin code is required")
	}
	startTime := time.Now()
	loadConfig()
	restore(skinCode)
	fmt.Println("time used: " + time.Since(startTime).String())
}

func saveImage(file string, img image.Image) {
	os.MkdirAll(filepath.Dir(file), 0777)
	fi, err := os.OpenFile(file, os.O_CREATE|os.O_RDWR|os.O_TRUNC, 0777)
	if err != nil {
		panic(err)
	}
	defer fi.Close()
	err = png.Encode(fi, img)
	if err != nil {
		panic(err)
	}
}

func fetchURL(url string, data interface{}) {
	response, err := http.Get(url)
	if err != nil {
		panic(err)
	}
	statusCode := response.StatusCode
	if statusCode >= 300 || statusCode < 200 {
		panic(errors.New("invalid response code: " + strconv.Itoa(statusCode)))
	}
	responseBody, err := io.ReadAll(response.Body)
	if err != nil {
		panic(err)
	}
	defer response.Body.Close()
	err = json.Unmarshal(responseBody, data)
	if err != nil {
		fmt.Println(data)
		panic(err)
	}
}

func loadJSON(file string, data interface{}) {
	fi, err := os.Open(file)
	if err != nil {
		panic(err)
	}
	defer fi.Close()
	content, err := io.ReadAll(fi)
	if err != nil {
		panic(err)
	}
	err = json.Unmarshal(content, data)
	if err != nil {
		panic(err)
	}
}

func saveJSON(file string, data interface{}) {
	content, err := json.MarshalIndent(data, "", "\t")
	if err != nil {
		panic(err)
	}
	err = os.MkdirAll(filepath.Dir(file), 0777)
	if err != nil {
		panic(err)
	}
	fi, err := os.OpenFile(file, os.O_CREATE|os.O_RDWR|os.O_TRUNC, 0777)
	if err != nil {
		panic(err)
	}
	defer fi.Close()
	_, err = fi.Write(content)
	if err != nil {
		panic(err)
	}
}

type Rect struct {
	Size     [2]float64 `json:"size"`
	Pivot    [2]float64 `json:"pivot"`
	Position [2]float64 `json:"position"`
	RawSize  [2]float64 `json:"rawSize"`
	View     [2]float64 `json:"view"`
	Raw      bool       `json:"raw"`
}

func loadRects(skinCode string) (map[string]*Rect, []string) {
	rects := map[string]*Rect{}
	localRectPath := fmt.Sprintf(config.LocalRectPath, skinCode)
	stat, err := os.Stat(localRectPath)
	if err != nil {
		if os.IsNotExist(err) {
			apiURL := fmt.Sprintf(config.RectAPI, skinCode, skinCode)
			fetchURL(apiURL, &rects)
			saveJSON(localRectPath, &rects)
		} else {
			panic(err)
		}
	} else {
		if stat.Size() != 0 {
			loadJSON(localRectPath, &rects)
		} else {
			apiURL := fmt.Sprintf(config.RectAPI, skinCode, skinCode)
			fetchURL(apiURL, &rects)
			saveJSON(localRectPath, &rects)
		}
	}
	keys := jsonKeys(localRectPath)
	return rects, keys
}

func restore(skinCode string) {
	var 
	(
		canvas *SyncImage = nil
		baseSize = []float64{}
	)
	rects, paintingsCodes := loadRects(skinCode)
	for _, paintingCode := range paintingsCodes {
		rect := rects[paintingCode]
		startTime := time.Now()
		painting := fmt.Sprintf(config.PaintingsInputPath, paintingCode)
		var dstw float64 
		var dsth float64
		var dst *SyncImage
		if !rect.Raw {
			meshPath := fmt.Sprintf(config.MeshInputPath, paintingCode)
			mesh := loadMesh(meshPath, paintingCode)
			defer mesh.Close()

			v := make([][2]float64, 0)
			vt := make([][2]float64, 0)
			buffer := bufio.NewReader(mesh)
			for {
				line, _, err := buffer.ReadLine()
				if err == io.EOF {
					break
				}
				if len(line) == 0 {
					continue
				}
				parts := strings.Split(string(line), " ")
				if line[0] == 'v' && line[1] == 't' {
					vt = append(vt, [2]float64{floatval(parts[1]), floatval(parts[2])})
				} else if line[0] == 'v' && line[1] != 't' {
					v = append(v, [2]float64{floatval(parts[1]), floatval(parts[2])})
				}
			}

			maxx := 0.0
			minx := 0.0
			maxy := 0.0
			miny := 0.0
			for _, item := range v {
				if item[0] < minx {
					minx = item[0]
				} else if item[0] > maxx {
					maxx = item[0]
				}
				if item[1] < miny {
					miny = item[1]
				} else if item[1] > maxy {
					maxy = item[1]
				}
			}

			dstw = maxx - minx + 1
			dsth = maxy - miny + 1

			dst = &SyncImage{
				img: image.NewRGBA(image.Rect(0, 0, int(dstw), int(dsth))),
			}
		OPEN_PAINTING:
			fi, err := os.Open(painting)
			if err != nil {
				err = download(fmt.Sprintf(config.PaintingAPI, skinCode, skinCode), painting)
				if err != nil {
					panic(err)
				}
				goto OPEN_PAINTING
			}
			defer fi.Close()

			src, err := png.Decode(fi)
			if err != nil {
				panic(err)
			}
			srcw := src.Bounds().Dx()
			srch := src.Bounds().Dy()

			src = flipImage(src, IMG_FLIP_VERTICAL)

			// 拼接碎块
			pwg := &sync.WaitGroup{}
			for index := 0; index < len(vt); index += 4 {
				pwg.Add(1)
				go func (index int)  {
					sx := int(vt[index][0]*float64(srcw) + 0.5)
					sy := int(vt[index][1]*float64(srch) + 0.5)
					ex := int(vt[index+2][0]*float64(srcw) + 0.5)
					ey := int(vt[index+2][1]*float64(srch) + 0.5)

					ax := int(math.Abs(v[index][0]))
					ay := int(math.Abs(v[index][1]))

					for x := sx; x < ex; x++ {
						for y := sy; y < ey; y++ {
							dx := x - sx
							dy := y - sy
							dst.Set(ax+dx, ay+dy, src.At(x, y))
						}
					}	
					pwg.Done()
				}(index)
			}
			pwg.Wait()
			fmt.Printf("restored %s (time used: %s)\r\n", paintingCode , time.Since(startTime).String())
		} else {
			paintingfi, err := os.Open(painting)
			if err != nil {
				panic(err)
			}
			defer paintingfi.Close()
			paintingImg, err := png.Decode(paintingfi)
			if err != nil {
				panic(err)
			}
			dstw = float64(paintingImg.Bounds().Dx())
			dsth = float64(paintingImg.Bounds().Dy())
			writablePaintingImg := image.NewRGBA(paintingImg.Bounds())
			for x := 0; x < int(dstw); x++ {
				for y := 0; y < int(dsth); y++ {
					writablePaintingImg.Set(x, y, paintingImg.At(x, y))
				}
			}
			
			dst = &SyncImage{
				img: writablePaintingImg,
			}

			dst.FlipImage(IMG_FLIP_VERTICAL)
		}

		// 合并图层
		size := rect.Size
		rawSize := rect.RawSize
		if size[0] == 0 || size[1] == 0 {
			if rawSize[0] > 0 && rawSize[1] > 0 {
				size = rawSize
			} else {
				size = [2]float64{dstw, dsth}
			}
		}
		pivot := rect.Pivot
		position := rect.Position
		
		if canvas == nil {
			canvas = &SyncImage{
				img: image.NewRGBA(image.Rect(0, 0, int(size[0]), int(size[1]))),
			}
		}

		dst.Resize(int(size[0]), int(size[1]))

		if paintingCode == skinCode {
			baseSize = size[:]
			canvas.Draw(dst.img.Rect, dst.img, image.Pt(0, 0), draw.Src)
		} else {
			px := int(baseSize[0]/2-size[0]*pivot[0]+position[0]+0.5)
			py := int(baseSize[1]/2-size[1]*pivot[1]+position[1]+0.5)
			offset := dst.img.Bounds().Add(image.Pt(px, py))
			canvas.Draw(offset, dst.img, image.Pt(0, 0), draw.Over)
		}
	}
	canvas.FlipImage(IMG_FLIP_VERTICAL)
	canvas.SaveImage(fmt.Sprintf(config.PaintingsOutputPath, skinCode, skinCode))
}

func loadMesh(meshPath string, paintingCode string) *os.File {
LoadMesh:
	mesh, err := os.Open(meshPath)
	if err != nil {
		err := download(fmt.Sprintf(config.MeshAPI, paintingCode, paintingCode), meshPath)
		if err != nil {
			panic(err)
		}
		goto LoadMesh
	}
	return mesh
}

// golang map不保留顺序 所以使用php的array_keys来获取json key的顺序
func jsonKeys(file string) []string {
	cmd := exec.Command("php", "keys.php", file)
	output, err := cmd.Output()
	if err != nil {
		panic(err)
	}
	keys := string(output)
	return strings.Split(keys, ",")
}

func floatval(s string) float64 {
	value, _ := strconv.ParseFloat(s, 64)
	return value
}

const (
	IMG_FLIP_HORIZONTAL = iota
	IMG_FLIP_VERTICAL
	IMG_FLIP_BOTH
)

type SyncImage struct {
	img *image.RGBA
	sync.RWMutex
}

func (s *SyncImage) Set(x, y int, c color.Color) {
	s.Lock()
	defer s.Unlock()
	s.img.Set(x, y, c)
}

func (s *SyncImage) SaveImage(file string) {
	saveImage(file, s.img)
}

func (s *SyncImage) FlipImage(mode int) {
	s.img = flipImage(s.img, mode)
}

func (s *SyncImage) Resize(width, height int, ) {
	s.img = resize.Resize(uint(width), uint(height), s.img, resize.Bicubic).(*image.RGBA)
}

func (s *SyncImage) Draw(rect image.Rectangle, src image.Image, sp image.Point, op draw.Op) {
	draw.Draw(s.img, rect, src, sp, op)
}

func flipImage(img image.Image, mode int) *image.RGBA {
	bounds := img.Bounds()
	imgw := bounds.Dx()
	imgh := bounds.Dy()
	dst := &SyncImage{
		img: image.NewRGBA(bounds),
	}
	wg := &sync.WaitGroup{}
	for x := 0; x < imgw; x++ {
		for y := 0; y < imgh; y++ {
			wg.Add(1)
			go func(x, y int) {
				if mode == IMG_FLIP_HORIZONTAL {
					dst.Set(imgw-x, y, img.At(x, y))
				} else if mode == IMG_FLIP_VERTICAL {
					dst.Set(x, imgh-y, img.At(x, y))
				} else if mode == IMG_FLIP_BOTH {
					dst.Set(imgw-x, imgh-y, img.At(x, y))
				}
				wg.Done()
			}(x, y)
		}
	}
	wg.Wait()
	return dst.img
}

func download(api string, file string) error {
	response, err := http.Get(api)
	if err != nil {
		return err
	}
	content, err := io.ReadAll(response.Body)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	fi, err := os.OpenFile(file, os.O_CREATE|os.O_RDWR|os.O_TRUNC, 0777)
	if err != nil {
		return err
	}
	defer fi.Close()
	_, err = fi.Write(content)
	return err
}

type Config struct {
	ShipListAPI string `json:"ship_list_api"`
	SkinListAPI string `json:"skin_list_api"`
	RectAPI string `json:"rect_api"`
	PaintingAPI string `json:"painting_api"`
	MeshAPI string `json:"mesh_api"`
	PaintingsInputPath string `json:"paintings_input_path"`
	PaintingsOutputPath string `json:"paintings_output_path"`
	MeshInputPath string `json:"mesh_input_path"`
	RestoredPaintingsJSON string `json:"restored_paintings_json"`
	LocalRectPath string `json:"local_rect_path"`
}

func loadConfig() {
	loadJSON("config.json", config)
}
